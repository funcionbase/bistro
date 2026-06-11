<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill de la invariante "toda sede tiene >=1 bodega activa".
 *
 * El onboarding (enrollment) y la creación de sedes nunca sembraban una
 * bodega default, así que sedes existentes quedaron sin ninguna. Eso rompía
 * crear insumos (404 "recurso no encontrado", porque no había bodega destino
 * para el stock inicial) y recibir compras. Esta migración crea una "Bodega
 * principal" (`main`, `is_default=true`) para cada sede que no tenga bodega
 * activa.
 *
 * IMPORTANTE: es self-contained (DB facade, no usa el modelo Warehouse). El
 * modelo evolucionó con #costeo-multibodega (bodega company-scoped + pivot),
 * pero a la altura de ESTA migración el esquema todavía tiene
 * `warehouses.branch_id NOT NULL` y aún no existe `branch_warehouses`. Llamar
 * al modelo aquí rompería un `migrate` fresco — por eso se insertan las filas
 * directamente con el esquema vigente en este punto.
 *
 * No tiene `down` reversible por diseño: borrar bodegas sembradas podría
 * dejar `ingredient_stocks` huérfanos. Es un backfill aditivo y seguro.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $branchesWithoutWarehouse = DB::table('branches')
            ->whereNull('archived_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('warehouses')
                    ->whereColumn('warehouses.branch_id', 'branches.id')
                    ->whereNull('warehouses.archived_at');
            })
            ->get(['id', 'company_nit']);

        foreach ($branchesWithoutWarehouse as $branch) {
            DB::table('warehouses')->insert([
                'id' => (string) Str::uuid7(),
                'company_nit' => $branch->company_nit,
                'branch_id' => $branch->id,
                'name' => 'Bodega principal',
                'slug' => 'principal',
                'type' => 'main',
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Backfill aditivo: no se revierte para no dejar ingredient_stocks
        // huérfanos si ya se usaron las bodegas sembradas.
    }
};
