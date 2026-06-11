<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WarehouseStockHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Histórico del valor del inventario por bodega (#120).
 *
 * GET /api/v1/inventory/history/valuation?from=YYYY-MM-DD&to=YYYY-MM-DD&warehouse_id=<uuid|null>
 *
 * Devuelve serie temporal:
 *   { series: [{ date, total_value, by_warehouse: [{warehouse_id, name, value}] }] }
 *
 * Si una fecha del rango no tiene snapshot, se hace lazy backfill desde
 * `ingredient_movements`.
 */
class InventoryHistoryController extends Controller
{
    public function __construct(private readonly WarehouseStockHistoryService $history) {}

    public function series(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'warehouse_id' => ['nullable', 'string', 'uuid'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now()->subDay()->startOfDay();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays(29);

        // Cap defensivo: máximo 365 días por consulta.
        if ($from->diffInDays($to) > 365) {
            $from = $to->copy()->subDays(365);
        }

        $series = $this->history->seriesBetween(
            $nit,
            $validated['warehouse_id'] ?? null,
            $from,
            $to,
        );

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'series' => $series,
            ],
        ]);
    }
}
