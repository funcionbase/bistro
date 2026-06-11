<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Costeo multi-sede/multi-bodega — Fase 1, migración 1/3.
 *
 * Introduce el pivot `branch_warehouses`: una bodega pasa de pertenecer a UNA
 * sede (`warehouses.branch_id`) a ser un recurso de empresa asignable a N
 * sedes. El pivot es la nueva fuente de verdad de la relación sede↔bodega y
 * de cuál bodega es la default de cada sede (para recetas/compras sin bodega
 * explícita).
 *
 * Estructura:
 *  - Unique (branch_id, warehouse_id): una bodega no se asigna dos veces a la
 *    misma sede.
 *  - Índice único parcial: una sola bodega `is_default=true` por sede.
 *
 * Backfill (idempotente, aditivo): por cada bodega actual se crea una fila de
 * pivot que conserva su `branch_id` y su `is_default` (solo si la bodega está
 * activa — una bodega archivada nunca queda como default de la sede). Esto
 * preserva 1:1 la relación existente como punto de partida; la migración
 * siguiente (`make_warehouse_company_scoped`) ya puede quitar `branch_id` de
 * `warehouses` sin perder información.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['branch_id', 'warehouse_id'], 'branch_warehouses_branch_warehouse_unique');
            $table->index(['company_nit', 'branch_id'], 'branch_warehouses_company_branch_idx');
            $table->index('warehouse_id', 'branch_warehouses_warehouse_idx');
        });

        // Una sola bodega default por sede (la que se usa cuando no se indica
        // bodega explícita en recetas/compras). Espejo del índice viejo
        // warehouses_one_default_per_branch.
        DB::statement('CREATE UNIQUE INDEX branch_warehouses_one_default_per_branch
            ON branch_warehouses (branch_id)
            WHERE is_default = TRUE');

        // Backfill 1:1 desde la relación actual warehouses.branch_id.
        $warehouses = DB::table('warehouses')->get(['id', 'company_nit', 'branch_id', 'is_default', 'archived_at']);
        $now = now();

        foreach ($warehouses as $warehouse) {
            DB::table('branch_warehouses')->insert([
                'id' => (string) Str::uuid7(),
                'company_nit' => $warehouse->company_nit,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                // Una bodega archivada no debe quedar como default de la sede.
                'is_default' => (bool) $warehouse->is_default && $warehouse->archived_at === null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS branch_warehouses_one_default_per_branch');
        Schema::dropIfExists('branch_warehouses');
    }
};
