<?php

declare(strict_types=1);

namespace App\Exceptions\Inventory;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * La sede no tiene ninguna bodega asignada, por lo que
 * no se puede crear inventario, recibir compras ni configurar costo de receta
 * en ella.
 *
 * Las bodegas son recursos de empresa asignables a N sedes (pivot
 * `branch_warehouses`). Con la regla D3, una sede solo recibe bodega
 * automáticamente si la empresa tiene exactamente una; con 2+ la asignación es
 * manual. Hasta que el usuario asigne una bodega, las operaciones de inventario
 * de esa sede se bloquean en duro.
 *
 * Se auto-renderiza como 422 con `code: BRANCH_HAS_NO_WAREHOUSE` para que el
 * frontend guíe a asignar una bodega sin parsear texto.
 */
class BranchHasNoWarehouseException extends RuntimeException
{
    public function __construct(
        public readonly string $branchId,
        string $message = 'Esta sede no tiene una bodega asignada. Asigna una bodega antes de gestionar inventario.',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'BRANCH_HAS_NO_WAREHOUSE',
            'branch_id' => $this->branchId,
        ], 422);
    }
}
