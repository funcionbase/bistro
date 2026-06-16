<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Warehouse;
use App\Rules\SafePlainText;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para transferencias de inventario entre bodegas de la MISMA sede.
 *
 * POST /api/v1/inventory/transfers
 *   { from_warehouse_id, to_warehouse_id, ingredient_id, quantity, reference? }
 *
 * Devuelve los dos movimientos generados (origen − / destino +) con su
 * referencia compartida (TRF-YYYYMMDDHHMMSS-<ingredient_id>).
 *
 * Aislamiento (#costeo-multibodega): tras el refactor multibodega las bodegas
 * son **company-scoped** (una bodega abastece N sedes vía pivot), así que este
 * endpoint permite transferir entre cualquier par de bodegas de la MISMA
 * empresa. Ambos `firstOrFail()` filtran por `company_nit`: un uuid de bodega de
 * otra empresa responde 404. (El docblock previo describía el modelo per-sede
 * anterior — quedó stale tras el refactor ya desplegado a pdn; drift §7.)
 */
class InventoryTransferController extends Controller
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function store(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $activeBranchId = (string) $request->attributes->get('active_branch_id');
        $actorId = (string) ($request->attributes->get('jwt_payload')['sub'] ?? '');

        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'string', 'uuid'],
            'to_warehouse_id' => ['required', 'string', 'uuid', 'different:from_warehouse_id'],
            'ingredient_id' => ['required', 'string', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
        ]);

        // (#costeo-multibodega) Las bodegas son company-scoped; el firstOrFail
        // por company_nit responde 404 si algún uuid no es de la empresa. Se
        // permite transferir entre cualquier par de bodegas de la empresa.
        $from = Warehouse::query()
            ->where('company_nit', $nit)
            ->where('id', $validated['from_warehouse_id'])
            ->firstOrFail();

        $to = Warehouse::query()
            ->where('company_nit', $nit)
            ->where('id', $validated['to_warehouse_id'])
            ->firstOrFail();

        $ingredient = Ingredient::query()
            ->where('company_nit', $nit)
            ->where('id', $validated['ingredient_id'])
            ->firstOrFail();

        $actor = $actorId > 0 ? User::find($actorId) : null;

        [$out, $in] = $this->inventoryService->transfer(
            $from,
            $to,
            $ingredient,
            (string) $validated['quantity'],
            $validated['reference'] ?? null,
            $actor,
            $activeBranchId,
        );

        return response()->json([
            'data' => [
                'reference' => $out->reference,
                'out' => [
                    'id' => $out->id,
                    'warehouse_id' => $out->warehouse_id,
                    'dest_warehouse_id' => $out->dest_warehouse_id,
                    'quantity' => $out->quantity,
                ],
                'in' => [
                    'id' => $in->id,
                    'warehouse_id' => $in->warehouse_id,
                    'dest_warehouse_id' => $in->dest_warehouse_id,
                    'quantity' => $in->quantity,
                ],
            ],
        ], 201);
    }
}
