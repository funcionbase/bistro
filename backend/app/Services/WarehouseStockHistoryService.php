<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Warehouse;
use App\Models\WarehouseStockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Snapshots históricos del stock por bodega (#120).
 *
 * Patrón alineado con `MenuItemCostHistory`:
 *  - `snapshotDaily(?date)`: idempotente, upsert por (warehouse, ingredient, date).
 *  - Fallback de reconstrucción: si se consulta una fecha sin snapshot,
 *    reconstruye desde `ingredient_movements` (SUM por warehouse hasta esa
 *    fecha) y persiste el resultado.
 *  - `valuationOn(companyNit, ?warehouseId, date)`: lee snapshot del día,
 *    activa fallback si no existe.
 *  - `seriesBetween(companyNit, ?warehouseId, from, to)`: serie temporal
 *    para gráficas (asegura un snapshot por día en el rango).
 *
 * Costo aplicado: `ingredient_stocks.current_cost` (WAC por bodega,
 * #costeo-multibodega) al momento del snapshot. Cada bodega valora su stock con
 * su propio costo promedio.
 */
class WarehouseStockHistoryService
{
    /**
     * Genera snapshots para una fecha (default: ayer al cierre operativo).
     * Idempotente vía upsert.
     *
     * @return int Cantidad de filas snapshot generadas/actualizadas.
     */
    public function snapshotDaily(?Carbon $date = null): int
    {
        $snapshotDate = ($date ?? now()->subDay()->endOfDay())->toDateString();
        $now = now();

        // (#costeo-multibodega) La bodega es company-scoped y puede servir a N
        // sedes; el snapshot es por (bodega, insumo) y atribuye la sede primaria
        // de la bodega (la default, o la asignación más antigua). Una bodega sin
        // asignación no genera snapshot (joinSub interno).
        $primaryBranch = DB::table('branch_warehouses')
            ->select('warehouse_id', DB::raw('(array_agg(branch_id ORDER BY is_default DESC, created_at ASC))[1] as branch_id'))
            ->groupBy('warehouse_id');

        $rows = DB::table('ingredient_stocks as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('ingredients as i', 'i.id', '=', 's.ingredient_id')
            ->joinSub($primaryBranch, 'pb', 'pb.warehouse_id', '=', 's.warehouse_id')
            ->whereNull('w.archived_at')
            ->whereNull('i.archived_at')
            ->select(
                'i.company_nit',
                'pb.branch_id',
                's.warehouse_id',
                's.ingredient_id',
                's.quantity',
                's.current_cost as unit_cost',
            )
            ->get();

        $payload = [];
        foreach ($rows as $row) {
            $lineValue = bcmul((string) $row->quantity, (string) $row->unit_cost, 2);

            $payload[] = [
                // PK uuid sin default en DB: el query builder (a diferencia de
                // Eloquent + HasUuids) no lo autogenera, hay que pasarlo o el
                // INSERT viola el NOT NULL. En conflicto (UPDATE) se ignora.
                'id' => (string) Str::uuid(),
                'company_nit' => $row->company_nit,
                'branch_id' => $row->branch_id,
                'warehouse_id' => $row->warehouse_id,
                'ingredient_id' => $row->ingredient_id,
                'snapshot_date' => $snapshotDate,
                'quantity' => $row->quantity,
                'unit_cost' => $row->unit_cost,
                'line_value' => $lineValue,
                'created_at' => $now,
            ];
        }

        if ($payload === []) {
            return 0;
        }

        DB::table('warehouse_stock_snapshots')->upsert(
            $payload,
            ['warehouse_id', 'ingredient_id', 'snapshot_date'],
            ['quantity', 'unit_cost', 'line_value', 'created_at'],
        );

        return count($payload);
    }

    /**
     * Valor del inventario en una fecha específica.
     *
     * Lee snapshot del día. Si no existe (fecha sin cron / antes de empezar
     * a snapshotar), activa fallback de reconstrucción desde movements.
     *
     * @return array{total: string, by_warehouse: list<array{warehouse_id:string,name:string,value:string}>}
     */
    public function valuationOn(string $companyNit, ?string $warehouseId, Carbon $date): array
    {
        $snapshotDate = $date->toDateString();

        $query = WarehouseStockSnapshot::query()
            ->where('company_nit', $companyNit)
            ->where('snapshot_date', $snapshotDate);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $exists = $query->exists();

        if (! $exists) {
            $this->reconstructFromMovements($companyNit, $warehouseId, $date);
        }

        $rows = DB::table('warehouse_stock_snapshots as wss')
            ->join('warehouses as w', 'w.id', '=', 'wss.warehouse_id')
            ->where('wss.company_nit', $companyNit)
            ->where('wss.snapshot_date', $snapshotDate)
            ->when($warehouseId !== null, fn ($q) => $q->where('wss.warehouse_id', $warehouseId))
            ->groupBy('wss.warehouse_id', 'w.name')
            ->select(
                'wss.warehouse_id',
                'w.name',
                DB::raw('SUM(wss.line_value) as value'),
            )
            ->orderBy('w.name')
            ->get();

        $total = '0.00';
        $byWarehouse = [];
        foreach ($rows as $row) {
            $value = (string) $row->value;
            $total = bcadd($total, $value, 2);
            $byWarehouse[] = [
                'warehouse_id' => $row->warehouse_id,
                'name' => $row->name,
                'value' => bcadd($value, '0', 2),
            ];
        }

        return [
            'total' => $total,
            'by_warehouse' => $byWarehouse,
        ];
    }

    /**
     * Serie temporal del valor del inventario por día en el rango [from, to].
     *
     * Asegura que cada día del rango tenga snapshot (lazy backfill).
     *
     * @return list<array{date: string, total_value: string, by_warehouse: list<array{warehouse_id:string,name:string,value:string}>}>
     */
    public function seriesBetween(string $companyNit, ?string $warehouseId, Carbon $from, Carbon $to): array
    {
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        $series = [];
        while ($cursor->lessThanOrEqualTo($end)) {
            $valuation = $this->valuationOn($companyNit, $warehouseId, $cursor);
            $series[] = [
                'date' => $cursor->toDateString(),
                'total_value' => $valuation['total'],
                'by_warehouse' => $valuation['by_warehouse'],
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Fallback: cuando se consulta una fecha sin snapshot, reconstruye desde
     * `ingredient_movements` el stock por (warehouse, ingredient) al final
     * del día solicitado y persiste el snapshot.
     *
     * Las transferencias quedan correctas porque cada lado del transfer
     * tiene un row con `warehouse_id` del bando correspondiente y `quantity`
     * con el signo apropiado (− origen, + destino).
     */
    private function reconstructFromMovements(string $companyNit, ?string $warehouseId, Carbon $date): void
    {
        $cutoff = $date->copy()->endOfDay();

        // (#costeo-multibodega) La bodega es company-scoped y puede servir a N
        // sedes. Atribuimos la sede PRIMARIA de la bodega (default/más antigua),
        // NO m.branch_id de cada movimiento: si agrupáramos por la sede de la
        // operación, una bodega compartida produciría varias filas con la misma
        // clave de conflicto (warehouse_id, ingredient_id, snapshot_date) y el
        // upsert reventaría ("ON CONFLICT cannot affect row a second time"),
        // además de fragmentar el stock por sede.
        $primaryBranch = DB::table('branch_warehouses')
            ->select('warehouse_id', DB::raw('(array_agg(branch_id ORDER BY is_default DESC, created_at ASC))[1] as branch_id'))
            ->groupBy('warehouse_id');

        $query = DB::table('ingredient_movements as m')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->join('ingredients as i', 'i.id', '=', 'm.ingredient_id')
            ->joinSub($primaryBranch, 'pb', 'pb.warehouse_id', '=', 'm.warehouse_id')
            // WAC por bodega: el costo es de (insumo, bodega) en ingredient_stocks.
            // leftJoin + COALESCE 0 por si el par no tiene fila de stock (stock
            // que quedó en 0 y se limpió). La reconstrucción histórica usa el
            // WAC actual como aproximación (igual que antes con el global).
            ->leftJoin('ingredient_stocks as st', function ($join) {
                $join->on('st.ingredient_id', '=', 'm.ingredient_id')
                    ->on('st.warehouse_id', '=', 'm.warehouse_id');
            })
            ->where('m.company_nit', $companyNit)
            ->where('m.created_at', '<=', $cutoff)
            ->whereNull('w.archived_at')
            ->whereNull('i.archived_at')
            ->select(
                'i.company_nit',
                'pb.branch_id',
                'm.warehouse_id',
                'm.ingredient_id',
                DB::raw('COALESCE(st.current_cost, 0) as unit_cost'),
                DB::raw('SUM(m.quantity) as quantity'),
            )
            ->groupBy('i.company_nit', 'pb.branch_id', 'm.warehouse_id', 'm.ingredient_id', 'st.current_cost');

        if ($warehouseId !== null) {
            $query->where('m.warehouse_id', $warehouseId);
        }

        $rows = $query->get();

        $now = now();
        $snapshotDate = $date->toDateString();

        $payload = [];
        foreach ($rows as $row) {
            $quantity = (string) $row->quantity;

            // CHECK constraint en la tabla obliga quantity >= 0. Si el
            // movimiento histórico arroja negativos (stock desactualizado
            // o mermas no registradas), recortamos a 0 — el snapshot no
            // es la fuente de verdad para reconciliación, sólo para
            // reportes históricos.
            if (bccomp($quantity, '0', 3) < 0) {
                $quantity = '0';
            }

            $lineValue = bcmul($quantity, (string) $row->unit_cost, 2);

            $payload[] = [
                // PK uuid sin default en DB — ver nota en snapshotDaily().
                'id' => (string) Str::uuid(),
                'company_nit' => $row->company_nit,
                'branch_id' => $row->branch_id,
                'warehouse_id' => $row->warehouse_id,
                'ingredient_id' => $row->ingredient_id,
                'snapshot_date' => $snapshotDate,
                'quantity' => $quantity,
                'unit_cost' => $row->unit_cost,
                'line_value' => $lineValue,
                'created_at' => $now,
            ];
        }

        if ($payload === []) {
            return;
        }

        DB::table('warehouse_stock_snapshots')->upsert(
            $payload,
            ['warehouse_id', 'ingredient_id', 'snapshot_date'],
            ['quantity', 'unit_cost', 'line_value', 'created_at'],
        );
    }
}
