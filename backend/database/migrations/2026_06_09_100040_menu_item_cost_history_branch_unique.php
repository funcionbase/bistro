<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Costeo multi-sede/multi-bodega — Fase 2, migración 5/7.
 *
 * El unique de `menu_item_cost_history` pasa de company-wide a por sede:
 * `(company_nit, menu_item_id, snapshot_date)` →
 * `(company_nit, branch_id, menu_item_id, snapshot_date)`.
 *
 * Bug que corrige: el snapshot diario deduplicaba por `menu_item_id` ("gana la
 * última sede"), así que con cartas clonadas (mismo `menu_item_id` en N sedes)
 * sólo sobrevivía un snapshot por día — el resto de sedes perdía su histórico
 * de food cost. Branch-keyear el unique permite un snapshot por (sede, item,
 * día). El `FoodCostMetricsService` se actualiza en la misma entrega para
 * dedupar por `branch_id|menu_item_id` y hacer upsert contra este unique.
 *
 * Las filas históricas ya traen `branch_id` poblado (la columna existe desde el
 * bloque inicial). El unique viejo era más estricto, así que branch-keyear no
 * introduce colisiones nuevas: si `(company, item, date)` era único,
 * `(company, branch, item, date)` también lo es.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El unique viejo se creó con $table->unique() → es una CONSTRAINT
        // (índice respaldado por constraint), no un índice suelto: hay que
        // soltarlo con DROP CONSTRAINT, no DROP INDEX.
        DB::statement('ALTER TABLE menu_item_cost_history DROP CONSTRAINT IF EXISTS mich_company_item_date_unique');
        DB::statement('CREATE UNIQUE INDEX mich_company_branch_item_date_unique
            ON menu_item_cost_history (company_nit, branch_id, menu_item_id, snapshot_date)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mich_company_branch_item_date_unique');

        // El unique viejo no tolera dos sedes con el mismo (company, item, date).
        // Si el branch-keyado permitió snapshots por sede, conservamos el más
        // reciente por (company, item, date) y borramos el resto antes de
        // recrear el índice viejo (append-only, pero el down es solo de QA).
        $dupes = DB::table('menu_item_cost_history')
            ->select('company_nit', 'menu_item_id', 'snapshot_date', DB::raw('count(*) as cnt'))
            ->groupBy('company_nit', 'menu_item_id', 'snapshot_date')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            $keepId = DB::table('menu_item_cost_history')
                ->where('company_nit', $dupe->company_nit)
                ->where('menu_item_id', $dupe->menu_item_id)
                ->where('snapshot_date', $dupe->snapshot_date)
                ->orderByDesc('created_at')
                ->value('id');

            DB::table('menu_item_cost_history')
                ->where('company_nit', $dupe->company_nit)
                ->where('menu_item_id', $dupe->menu_item_id)
                ->where('snapshot_date', $dupe->snapshot_date)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        DB::statement('ALTER TABLE menu_item_cost_history
            ADD CONSTRAINT mich_company_item_date_unique UNIQUE (company_nit, menu_item_id, snapshot_date)');
    }
};
