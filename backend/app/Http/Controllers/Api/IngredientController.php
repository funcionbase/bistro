<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Inventory\BranchHasNoWarehouseException;
use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreIngredientRequest;
use App\Http\Requests\Inventory\UpdateIngredientRequest;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de insumos por empresa (multibodega #120).
 *
 * El stock vive en `ingredient_stocks` por (ingredient, warehouse). Este
 * controller expone para cada insumo:
 *  - `stocks[]`: cantidad y min_stock por bodega activa de la sede.
 *  - `total_stock`: suma agregada de stocks (compatibilidad UI).
 *  - `is_low_stock`: true si CUALQUIER bodega activa está por debajo de
 *    su `min_stock` configurado.
 *
 * Endpoints:
 *  - GET    /api/v1/inventory/ingredients
 *      ?warehouse_id=<uuid|null>&low_stock=1&archived=0&category=...&q=...
 *  - POST   /api/v1/inventory/ingredients
 *      { name, unit, category?, initial_stock?, initial_cost?,
 *        warehouse_id (default si no se pasa) }
 *  - GET    /api/v1/inventory/ingredients/{id}
 *  - PATCH  /api/v1/inventory/ingredients/{id}
 *  - DELETE /api/v1/inventory/ingredients/{id}        — archivar
 *  - POST   /api/v1/inventory/ingredients/{id}/restore
 *  - GET    /api/v1/inventory/valuation?warehouse_id=<uuid|null>
 *
 * Para mutar stock usar IngredientMovementController (entry/waste/adjustment)
 * o InventoryTransferController (transfer).
 */
class IngredientController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $warehouseId = $request->query('warehouse_id');

        $query = Ingredient::forCompany($companyNit);

        if ($request->boolean('archived')) {
            $query->archived();
        } else {
            $query->active();
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where('name', 'ilike', '%'.$q.'%');
        }

        if ($category = trim((string) $request->input('category', ''))) {
            $query->where('category', $category);
        }

        // (#costeo-multibodega) El catálogo de insumos es company-wide (sin
        // BranchScope): se listan todos los insumos de la empresa. El stock que
        // se serializa SÍ es por sede (sólo las bodegas asignadas a la sede
        // activa, vía pivot — ver fetchStocksGroupedByIngredient).
        $perPage = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->orderBy('name')->paginate($perPage);

        $ingredientIds = $paginated->getCollection()->pluck('id')->all();
        $stocksByIngredient = $this->fetchStocksGroupedByIngredient($ingredientIds, $branchId);

        $data = $paginated->getCollection()
            ->map(fn (Ingredient $i) => $this->serialize($i, $stocksByIngredient[$i->id] ?? [], $warehouseId))
            ->all();

        // Filtro low_stock post-serialización si llegó el flag (cuesta poco
        // y mantiene la SQL principal simple).
        if ($request->boolean('low_stock')) {
            $data = array_values(array_filter($data, fn ($d) => $d['is_low_stock']));
        }

        $lowStockCount = $this->lowStockCount($companyNit, $branchId);

        $categories = Ingredient::forCompany($companyNit)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();

        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'meta' => [
                'low_stock_count' => $lowStockCount,
                'categories' => $categories,
            ],
        ]);
    }

    public function store(StoreIngredientRequest $request): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $user = $this->actingUser($request);
        $validated = $request->validated();

        $warehouse = $this->resolveTargetWarehouse(
            $companyNit,
            $branchId,
            $request->input('warehouse_id'),
        );

        $ingredient = DB::transaction(function () use ($companyNit, $validated, $warehouse) {
            // (#costeo-multibodega) El insumo es catálogo de empresa: sin
            // branch_id y sin costo global (el WAC vive por bodega en
            // ingredient_stocks.current_cost).
            $ingredient = Ingredient::create([
                'company_nit' => $companyNit,
                'name' => $validated['name'],
                'category' => $validated['category'] ?? null,
                'unit' => $validated['unit'],
            ]);

            // Fila inicial en ingredient_stocks (quantity=0). Permite que
            // los reportes vean el insumo en la bodega aunque no haya
            // movimientos todavía.
            IngredientStock::query()->firstOrCreate(
                ['ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id],
                ['quantity' => 0, 'min_stock' => $validated['min_stock'] ?? 0, 'updated_at' => now()],
            );

            return $ingredient;
        });

        $this->auditService->log('inventory.ingredient.created', $user, $ingredient, [
            'name' => $ingredient->name,
            'unit' => $ingredient->unit,
            'warehouse_id' => $warehouse->id,
        ]);

        if (! empty($validated['initial_stock']) && ! empty($validated['initial_cost'])) {
            $this->inventory->recordMovement(
                ingredient: $ingredient,
                warehouse: $warehouse,
                type: InventoryService::TYPE_ENTRY,
                signedQuantity: (string) $validated['initial_stock'],
                unitCost: (string) $validated['initial_cost'],
                reference: $validated['reference'] ?? 'Existencias iniciales',
                actor: $user,
                branchId: $branchId,
            );
            $ingredient->refresh();
        }

        $stocks = $this->fetchStocksGroupedByIngredient([$ingredient->id], $branchId)[$ingredient->id] ?? [];

        return response()->json(['data' => $this->serialize($ingredient, $stocks)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $ingredient = Ingredient::forCompany($companyNit)->findOrFail($id);

        $stocks = $this->fetchStocksGroupedByIngredient([$ingredient->id], $branchId)[$ingredient->id] ?? [];

        return response()->json(['data' => $this->serialize($ingredient, $stocks)]);
    }

    public function update(UpdateIngredientRequest $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $ingredient = Ingredient::forCompany($companyNit)->findOrFail($id);

        $validated = $request->validated();
        $before = $ingredient->only(['name', 'category', 'unit']);

        DB::transaction(function () use ($ingredient, $validated, $branchId) {
            // Min_stock ahora vive en ingredient_stocks por bodega. Si el
            // request lo trae, asumimos warehouse default de la sede activa.
            $minStock = $validated['min_stock'] ?? null;
            unset($validated['min_stock']);

            $ingredient->fill($validated)->save();

            if ($minStock !== null) {
                $warehouseId = $this->resolveDefaultWarehouseId($ingredient->company_nit, $branchId);

                IngredientStock::query()
                    ->updateOrInsert(
                        ['ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouseId],
                        ['min_stock' => $minStock, 'updated_at' => now()],
                    );
            }
        });

        $this->auditService->log('inventory.ingredient.updated', $this->actingUser($request), $ingredient, [
            'before' => $before,
            'after' => $ingredient->only(['name', 'category', 'unit']),
        ]);

        $stocks = $this->fetchStocksGroupedByIngredient([$ingredient->id], $branchId)[$ingredient->id] ?? [];

        return response()->json(['data' => $this->serialize($ingredient, $stocks)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $ingredient = Ingredient::forCompany($companyNit)->findOrFail($id);

        $ingredient->forceFill(['archived_at' => now()])->save();

        $this->auditService->log('inventory.ingredient.archived', $this->actingUser($request), $ingredient, [
            'name' => $ingredient->name,
        ]);

        $stocks = $this->fetchStocksGroupedByIngredient([$ingredient->id], $branchId)[$ingredient->id] ?? [];

        return response()->json(['data' => $this->serialize($ingredient, $stocks)]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $ingredient = Ingredient::forCompany($companyNit)->findOrFail($id);

        $ingredient->forceFill(['archived_at' => null])->save();

        $this->auditService->log('inventory.ingredient.restored', $this->actingUser($request), $ingredient, [
            'name' => $ingredient->name,
        ]);

        $stocks = $this->fetchStocksGroupedByIngredient([$ingredient->id], $branchId)[$ingredient->id] ?? [];

        return response()->json(['data' => $this->serialize($ingredient, $stocks)]);
    }

    public function valuation(Request $request): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $warehouseId = $request->query('warehouse_id');

        return response()->json([
            'data' => $this->inventory->valuation($companyNit, $warehouseId),
        ]);
    }

    /**
     * @param  list<string>  $ingredientIds
     * @return array<string, list<array{warehouse_id: string, name: string, quantity: string, min_stock: string, current_cost: string}>>
     */
    private function fetchStocksGroupedByIngredient(array $ingredientIds, string $branchId): array
    {
        if ($ingredientIds === []) {
            return [];
        }

        // (#costeo-multibodega) La bodega es company-scoped; el vínculo con la
        // sede vive en el pivot branch_warehouses y la default es por sede. El
        // WAC vive por bodega en ingredient_stocks.current_cost.
        $rows = DB::table('ingredient_stocks as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('branch_warehouses as bw', 'bw.warehouse_id', '=', 'w.id')
            ->whereIn('s.ingredient_id', $ingredientIds)
            ->where('bw.branch_id', $branchId)
            ->whereNull('w.archived_at')
            ->orderByDesc('bw.is_default')
            ->orderBy('w.name')
            ->select('s.ingredient_id', 's.warehouse_id', 'w.name', 's.quantity', 's.min_stock', 's.current_cost')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->ingredient_id][] = [
                'warehouse_id' => $row->warehouse_id,
                'name' => $row->name,
                'quantity' => (string) $row->quantity,
                'min_stock' => (string) $row->min_stock,
                'current_cost' => (string) $row->current_cost,
            ];
        }

        return $grouped;
    }

    private function lowStockCount(string $companyNit, string $branchId): int
    {
        return (int) DB::table('ingredient_stocks as s')
            ->join('ingredients as i', 'i.id', '=', 's.ingredient_id')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('branch_warehouses as bw', 'bw.warehouse_id', '=', 'w.id')
            ->where('i.company_nit', $companyNit)
            ->where('bw.branch_id', $branchId)
            ->whereNull('i.archived_at')
            ->whereNull('w.archived_at')
            ->where('s.min_stock', '>', 0)
            ->whereColumn('s.quantity', '<', 's.min_stock')
            ->count();
    }

    /**
     * @param  list<array{warehouse_id: string, name: string, quantity: string, min_stock: string, current_cost: string}>  $stocks
     * @return array<string, mixed>
     */
    private function serialize(Ingredient $i, array $stocks, ?string $warehouseFilter = null): array
    {
        $totalStock = 0.0;
        $isLow = false;
        $filteredStock = null;

        // WAC por bodega (#costeo-multibodega): el insumo ya no tiene un costo
        // único. Para la UI se expone un costo ponderado entre bodegas
        // (valor total / stock total) en bcmath para no driftear en COP.
        $totalValue = '0.00';
        $totalQty = '0.000';

        foreach ($stocks as $s) {
            $qty = (float) $s['quantity'];
            $min = (float) $s['min_stock'];
            $totalStock += $qty;

            $totalValue = bcadd($totalValue, bcmul($s['quantity'], $s['current_cost'], 2), 2);
            $totalQty = bcadd($totalQty, $s['quantity'], 3);

            if ($min > 0 && $qty < $min) {
                $isLow = true;
            }

            if ($warehouseFilter !== null && $s['warehouse_id'] === $warehouseFilter) {
                $filteredStock = $s;
            }
        }

        $weightedCost = bccomp($totalQty, '0', 3) > 0 ? bcdiv($totalValue, $totalQty, 2) : '0.00';

        return [
            'id' => $i->id,
            'name' => $i->name,
            'category' => $i->category,
            'unit' => $i->unit,
            // Costo ponderado entre bodegas asignadas a la sede activa.
            'current_cost' => $weightedCost,
            'total_stock' => number_format($totalStock, 3, '.', ''),
            'is_low_stock' => $isLow,
            'stocks' => $stocks,
            'filtered_stock' => $filteredStock,
            'archived_at' => $i->archived_at?->toIso8601String(),
        ];
    }

    private function resolveTargetWarehouse(string $companyNit, string $branchId, mixed $warehouseId): Warehouse
    {
        if ($warehouseId !== null) {
            // (#costeo-multibodega) La bodega es company-scoped; debe estar
            // asignada a la sede (pivot branch_warehouses) para operar en ella.
            return Warehouse::query()
                ->where('company_nit', $companyNit)
                ->forBranch($branchId)
                ->where('id', (string) $warehouseId)
                ->whereNull('archived_at')
                ->firstOrFail();
        }

        // Sin bodega explícita: la default de la sede (regla D3 de
        // auto-asignación). Si la empresa tiene 2+ bodegas y la sede no tiene
        // ninguna asignada, `ensureDefaultForBranch` devuelve null → bloqueo
        // duro BRANCH_HAS_NO_WAREHOUSE: la UI debe guiar a asignar una bodega.
        $warehouse = Warehouse::ensureDefaultForBranch($companyNit, $branchId);

        if ($warehouse === null) {
            throw new BranchHasNoWarehouseException($branchId);
        }

        return $warehouse;
    }

    private function resolveDefaultWarehouseId(string $companyNit, string $branchId): string
    {
        return $this->resolveTargetWarehouse($companyNit, $branchId, null)->id;
    }
}
