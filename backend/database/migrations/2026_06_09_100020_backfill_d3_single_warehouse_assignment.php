<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Costeo multi-sede/multi-bodega — Fase 1, migración 3/3 (regla D3).
 *
 * Backfill de la regla de auto-asignación: toda sede activa que quede SIN
 * bodega default, si su empresa tiene **exactamente una** bodega activa, se le
 * asigna esa bodega como default. Con 2+ bodegas la sede arranca sin bodega y
 * la asignación es manual (bloqueo duro BRANCH_HAS_NO_WAREHOUSE en runtime).
 *
 * Tras `create_branch_warehouses_pivot` (backfill 1:1) la mayoría de sedes ya
 * tienen default; esto cubre sedes cuya única bodega quedó archivada o que
 * nunca tuvieron una. Aditivo e idempotente; no tiene down reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Empresas con exactamente una bodega activa. `min(id::text)` porque
        // Postgres no tiene agregado min(uuid); como el grupo tiene count=1, el
        // único id sobrevive al min sobre su representación textual.
        $singleWarehouseCompanies = DB::table('warehouses')
            ->whereNull('archived_at')
            ->select('company_nit', DB::raw('count(*) as cnt'), DB::raw('min(id::text) as warehouse_id'))
            ->groupBy('company_nit')
            ->havingRaw('count(*) = 1')
            ->get()
            ->keyBy('company_nit');

        if ($singleWarehouseCompanies->isEmpty()) {
            return;
        }

        // Sedes activas sin bodega default en el pivot.
        $branches = DB::table('branches')
            ->whereNull('archived_at')
            ->whereIn('company_nit', $singleWarehouseCompanies->keys()->all())
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('branch_warehouses')
                    ->whereColumn('branch_warehouses.branch_id', 'branches.id')
                    ->where('branch_warehouses.is_default', true);
            })
            ->get(['id', 'company_nit']);

        foreach ($branches as $branch) {
            $warehouseId = (string) $singleWarehouseCompanies[$branch->company_nit]->warehouse_id;

            // upsert: si ya existe la asignación (no-default), promoverla; si no, crearla.
            $existing = DB::table('branch_warehouses')
                ->where('branch_id', $branch->id)
                ->where('warehouse_id', $warehouseId)
                ->first(['id']);

            if ($existing !== null) {
                DB::table('branch_warehouses')->where('id', $existing->id)->update([
                    'is_default' => true,
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('branch_warehouses')->insert([
                'id' => (string) Str::uuid7(),
                'company_nit' => $branch->company_nit,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouseId,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Backfill aditivo: no se revierte (dejar la asignación es inocuo).
    }
};
