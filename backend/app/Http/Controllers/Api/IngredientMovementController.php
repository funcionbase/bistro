<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RecordAdjustmentRequest;
use App\Http\Requests\Inventory\RecordEntryRequest;
use App\Http\Requests\Inventory\RecordWasteRequest;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\IngredientStock;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Movimientos de inventario por bodega (#120). Append-only.
 *
 * Cada movimiento (entry/waste/adjustment) requiere `warehouse_id` en el
 * body — si no se provee, se usa la bodega default de la sede activa.
 *
 * - GET  /api/v1/inventory/ingredients/{id}/movements              — historial paginado (todos los movimientos del insumo).
 * - POST /api/v1/inventory/ingredients/{id}/movements/entry        — entrada en bodega X.
 * - POST /api/v1/inventory/ingredients/{id}/movements/waste        — merma en bodega X.
 * - POST /api/v1/inventory/ingredients/{id}/movements/adjustment   — ajuste manual ± en bodega X.
 *
 * Transferencias entre bodegas: InventoryTransferController (POST /api/v1/inventory/transfers).
 */
class IngredientMovementController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $ingredient = Ingredient::forCompany($companyNit)->findOrFail($id);

        $perPage = min((int) $request->input('per_page', 30), 200);
        // (#costeo-multibodega) Insumo y movimientos son company-level (plan §8):
        // el historial del insumo es company-wide (no de la sede activa) e incluye
        // los movimientos en TODAS sus bodegas — entradas, mermas, ajustes y ambas
        // patas de las transferencias entre bodegas. Se eager-loadean los nombres
        // de bodega (origen y destino) para mostrar la dirección del traslado.
        $paginated = IngredientMovement::withoutBranchScope()
            ->forCompany($companyNit)
            ->forIngredient($ingredient->id)
            ->with(['actor:id,name', 'warehouse:id,name', 'destinationWarehouse:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (IngredientMovement $m) => $this->serialize($m))->all(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function entry(RecordEntryRequest $request, string $id): JsonResponse
    {
        $ingredient = $this->resolveIngredient($request, $id);
        $warehouse = $this->resolveWarehouse($request, $ingredient);
        $validated = $request->validated();

        $movement = $this->inventory->recordMovement(
            ingredient: $ingredient,
            warehouse: $warehouse,
            type: InventoryService::TYPE_ENTRY,
            signedQuantity: (string) $validated['quantity'],
            unitCost: (string) $validated['unit_cost'],
            reference: $validated['reference'] ?? null,
            actor: $this->actingUser($request),
            branchId: $this->activeBranchId($request),
        );

        return $this->respondWithMovement($movement, $warehouse, 201);
    }

    public function waste(RecordWasteRequest $request, string $id): JsonResponse
    {
        $ingredient = $this->resolveIngredient($request, $id);
        $warehouse = $this->resolveWarehouse($request, $ingredient);
        $validated = $request->validated();

        $movement = $this->inventory->recordMovement(
            ingredient: $ingredient,
            warehouse: $warehouse,
            type: InventoryService::TYPE_WASTE,
            signedQuantity: '-'.((string) $validated['quantity']),
            unitCost: null,
            reference: $validated['reference'],
            actor: $this->actingUser($request),
            branchId: $this->activeBranchId($request),
        );

        return $this->respondWithMovement($movement, $warehouse, 201);
    }

    public function adjustment(RecordAdjustmentRequest $request, string $id): JsonResponse
    {
        $ingredient = $this->resolveIngredient($request, $id);
        $warehouse = $this->resolveWarehouse($request, $ingredient);
        $validated = $request->validated();

        $movement = $this->inventory->recordMovement(
            ingredient: $ingredient,
            warehouse: $warehouse,
            type: InventoryService::TYPE_ADJUSTMENT,
            signedQuantity: (string) $validated['quantity'],
            unitCost: null,
            reference: $validated['reference'],
            actor: $this->actingUser($request),
            branchId: $this->activeBranchId($request),
        );

        return $this->respondWithMovement($movement, $warehouse, 201);
    }

    private function resolveIngredient(Request $request, string $id): Ingredient
    {
        $companyNit = $this->activeCompanyNit($request);

        return Ingredient::forCompany($companyNit)->findOrFail($id);
    }

    /**
     * Resuelve warehouse desde request->warehouse_id o, en su defecto, usa la
     * bodega default de la sede activa.
     */
    private function resolveWarehouse(Request $request, Ingredient $ingredient): Warehouse
    {
        $branchId = $this->activeBranchId($request);
        $warehouseId = $request->input('warehouse_id');

        if ($warehouseId !== null) {
            // (#costeo-multibodega) La bodega es company-scoped; debe estar
            // asignada a la sede activa (pivot branch_warehouses).
            $warehouse = Warehouse::query()
                ->where('company_nit', $ingredient->company_nit)
                ->forBranch($branchId)
                ->where('id', (string) $warehouseId)
                ->whereNull('archived_at')
                ->first();

            if ($warehouse === null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'La bodega no existe o no está asignada a la sede activa.',
                ]);
            }

            return $warehouse;
        }

        $default = Warehouse::defaultForBranch($branchId);

        if ($default === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Esta sede no tiene una bodega asignada; asigna una bodega o especifica una.',
            ]);
        }

        return $default;
    }

    private function respondWithMovement(IngredientMovement $movement, Warehouse $warehouse, int $status): JsonResponse
    {
        $ingredient = $movement->ingredient;

        $stock = IngredientStock::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        // WAC por bodega (#costeo-multibodega): el costo relevante es el de la
        // bodega del movimiento, no un costo global del insumo.
        $warehouseCost = $stock ? (string) $stock->current_cost : '0.00';

        return response()->json([
            'data' => [
                'movement' => $this->serialize($movement),
                'ingredient' => [
                    'id' => $ingredient->id,
                    'current_cost' => $warehouseCost,
                ],
                'stock' => [
                    'warehouse_id' => $warehouse->id,
                    'warehouse_name' => $warehouse->name,
                    'quantity' => $stock ? (string) $stock->quantity : '0.000',
                    'min_stock' => $stock ? (string) $stock->min_stock : '0.000',
                    'current_cost' => $warehouseCost,
                ],
            ],
        ], $status);
    }

    /** @return array<string, mixed> */
    private function serialize(IngredientMovement $m): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type,
            'quantity' => (string) $m->quantity,
            'unit_cost' => $m->unit_cost !== null ? (string) $m->unit_cost : null,
            'warehouse_id' => $m->warehouse_id,
            'warehouse_name' => $m->warehouse?->name,
            'dest_warehouse_id' => $m->dest_warehouse_id,
            'dest_warehouse_name' => $m->destinationWarehouse?->name,
            'reference' => $m->reference,
            'created_at' => $m->created_at?->toIso8601String(),
            'actor' => $m->actor ? ['id' => $m->actor->id, 'name' => $m->actor->name] : null,
        ];
    }
}
