<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Costeo multi-sede/multi-bodega — Fase 1, migración 2/3.
 *
 * Quita `warehouses.branch_id`: la bodega deja de pertenecer a una sede y pasa
 * a ser un recurso de empresa. La relación sede↔bodega vive ahora en el pivot
 * `branch_warehouses` (migración previa) y la default de cada sede también.
 *
 * Cambios:
 *  - Drop índice único parcial `warehouses_one_default_per_branch` (la
 *    invariante "una default por sede" se movió al pivot).
 *  - Drop unique `(company_nit, branch_id, slug)` y FK/columna `branch_id`.
 *  - Dedup de slugs: el slug era único por (company, branch); al volverse
 *    company-wide dos sedes con bodega `principal` colisionarían. Se renombran
 *    los duplicados conservando el más antiguo como canónico.
 *  - Nuevo unique `(company_nit, slug)` + índice `(company_nit, archived_at)`.
 *
 * Reversible en QA: el down() recrea `branch_id` (nullable→backfill desde el
 * pivot→constraints). No reconstruye slugs renombrados (es seguro: siguen
 * siendo únicos).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Dedup de slugs colisionantes por empresa (conservar el más antiguo).
        $rows = DB::table('warehouses')
            ->orderBy('company_nit')
            ->orderBy('created_at')
            ->get(['id', 'company_nit', 'slug']);

        $seen = [];
        foreach ($rows as $row) {
            $key = $row->company_nit.'|'.$row->slug;
            if (! isset($seen[$key])) {
                $seen[$key] = 1;

                continue;
            }

            $seen[$key]++;
            $suffix = '-'.$seen[$key];
            $base = substr($row->slug, 0, 64 - strlen($suffix));
            DB::table('warehouses')->where('id', $row->id)->update(['slug' => $base.$suffix]);
        }

        // 2) Drop de constraints/índices que dependen de branch_id.
        DB::statement('DROP INDEX IF EXISTS warehouses_one_default_per_branch');

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropUnique('warehouses_company_branch_slug_unique');
            $table->dropIndex('warehouses_company_branch_archived_idx');
        });

        // 3) Drop columna + nuevos índices company-scoped.
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('branch_id');
            $table->unique(['company_nit', 'slug'], 'warehouses_company_slug_unique');
            $table->index(['company_nit', 'archived_at'], 'warehouses_company_archived_idx');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique('warehouses_company_slug_unique');
            $table->dropIndex('warehouses_company_archived_idx');
            $table->uuid('branch_id')->nullable()->after('company_nit');
        });

        // Backfill branch_id desde el pivot: la default primero, si no la
        // primera asignación de la bodega.
        $warehouses = DB::table('warehouses')->get(['id']);
        foreach ($warehouses as $warehouse) {
            $branchId = DB::table('branch_warehouses')
                ->where('warehouse_id', $warehouse->id)
                ->orderByDesc('is_default')
                ->value('branch_id');

            if ($branchId !== null) {
                DB::table('warehouses')->where('id', $warehouse->id)->update(['branch_id' => $branchId]);
            }
        }

        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['company_nit', 'branch_id', 'slug'], 'warehouses_company_branch_slug_unique');
            $table->index(['company_nit', 'branch_id', 'archived_at'], 'warehouses_company_branch_archived_idx');
        });

        DB::statement('CREATE UNIQUE INDEX warehouses_one_default_per_branch
            ON warehouses (branch_id)
            WHERE is_default = TRUE AND archived_at IS NULL');
    }
};
