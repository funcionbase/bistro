<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Costeo multi-sede/multi-bodega — Fase 2, migración 4/7.
 *
 * Dos cambios sobre `recipes`:
 *
 *  1. `warehouse_id` pasa a NOT NULL: la bodega de la línea es la **fuente de
 *     costo** del insumo en esa receta (#costeo-multibodega). Backfill de las
 *     filas con `warehouse_id` NULL usando la bodega default de la sede de la
 *     receta (vía pivot `branch_warehouses`); si la sede no tuviera default,
 *     cae a cualquier bodega activa asignada a esa sede, y como último recurso
 *     a la única/ primera bodega activa de la empresa. Si una receta quedara
 *     sin ninguna bodega candidata, el `SET NOT NULL` falla en QA (deseado: no
 *     se debe llevar a PDN una receta huérfana de bodega).
 *
 *  2. El unique parcial deja de ser company-wide y pasa a ser **por sede**:
 *     `(company_nit, menu_item_id, ingredient_id)` →
 *     `(company_nit, branch_id, menu_item_id, ingredient_id)`.
 *
 *     Por qué (y por qué ANTES de consolidar insumos): el bug de clonado de
 *     carta deja a dos sedes con el MISMO `menu_item_id`. Con el unique viejo,
 *     re-apuntar `recipes.ingredient_id` al insumo superviviente (migración de
 *     consolidación) violaría `(company, menu_item_id, ingredient)` cuando dos
 *     sedes comparten item. Branch-scoping el unique primero elimina esa
 *     colisión: cada sede tiene su propio espacio de unicidad.
 *
 * Reversible en QA (down recrea el unique company-wide y vuelve warehouse_id
 * nullable). El down dedup-a por (company, item, ingredient) conservando la
 * fila activa más reciente si el branch-scoping permitió duplicados que el
 * unique viejo no toleraría.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Backfill de warehouse_id NULL con la bodega de costeo de la sede.
        $recipes = DB::table('recipes')
            ->whereNull('warehouse_id')
            ->whereNull('archived_at')
            ->get(['id', 'company_nit', 'branch_id']);

        foreach ($recipes as $recipe) {
            $warehouseId = $this->resolveWarehouseForBranch($recipe->company_nit, $recipe->branch_id);

            if ($warehouseId !== null) {
                DB::table('recipes')->where('id', $recipe->id)->update(['warehouse_id' => $warehouseId]);
            }
        }

        // Filas archivadas con warehouse_id NULL: backfill best-effort para no
        // bloquear el SET NOT NULL (no costean nada, pero la columna no admite
        // NULL). Se usa la misma resolución; si no hay bodega, quedan NULL y el
        // SET NOT NULL las delata en QA.
        $archived = DB::table('recipes')
            ->whereNull('warehouse_id')
            ->get(['id', 'company_nit', 'branch_id']);

        foreach ($archived as $recipe) {
            $warehouseId = $this->resolveWarehouseForBranch($recipe->company_nit, $recipe->branch_id);
            if ($warehouseId !== null) {
                DB::table('recipes')->where('id', $recipe->id)->update(['warehouse_id' => $warehouseId]);
            }
        }

        DB::statement('ALTER TABLE recipes ALTER COLUMN warehouse_id SET NOT NULL');

        // 2) Unique company-wide → unique por sede.
        DB::statement('DROP INDEX IF EXISTS recipes_company_item_ingredient_unique');
        DB::statement('CREATE UNIQUE INDEX recipes_company_branch_item_ingredient_unique
            ON recipes (company_nit, branch_id, menu_item_id, ingredient_id)
            WHERE archived_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recipes_company_branch_item_ingredient_unique');

        // El unique viejo es más estricto (sin branch). Si el branch-scoping
        // habilitó dos filas activas con el mismo (company, item, ingredient)
        // en sedes distintas, conservamos la más reciente y archivamos el resto
        // para que el índice viejo no falle.
        $dupes = DB::table('recipes')
            ->whereNull('archived_at')
            ->select('company_nit', 'menu_item_id', 'ingredient_id', DB::raw('count(*) as cnt'))
            ->groupBy('company_nit', 'menu_item_id', 'ingredient_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            $keepId = DB::table('recipes')
                ->where('company_nit', $dupe->company_nit)
                ->where('menu_item_id', $dupe->menu_item_id)
                ->where('ingredient_id', $dupe->ingredient_id)
                ->whereNull('archived_at')
                ->orderByDesc('created_at')
                ->value('id');

            DB::table('recipes')
                ->where('company_nit', $dupe->company_nit)
                ->where('menu_item_id', $dupe->menu_item_id)
                ->where('ingredient_id', $dupe->ingredient_id)
                ->whereNull('archived_at')
                ->where('id', '!=', $keepId)
                ->update(['archived_at' => now()]);
        }

        DB::statement('ALTER TABLE recipes ALTER COLUMN warehouse_id DROP NOT NULL');
        DB::statement('CREATE UNIQUE INDEX recipes_company_item_ingredient_unique
            ON recipes (company_nit, menu_item_id, ingredient_id)
            WHERE archived_at IS NULL');
    }

    /**
     * Bodega de costeo de una sede: default del pivot → cualquier bodega activa
     * asignada a la sede → única/primera bodega activa de la empresa. null si la
     * empresa no tiene ninguna bodega activa.
     */
    private function resolveWarehouseForBranch(string $companyNit, string $branchId): ?string
    {
        $assigned = DB::table('warehouses as w')
            ->join('branch_warehouses as bw', 'bw.warehouse_id', '=', 'w.id')
            ->where('w.company_nit', $companyNit)
            ->where('bw.branch_id', $branchId)
            ->whereNull('w.archived_at')
            ->orderByDesc('bw.is_default')
            ->orderBy('w.name')
            ->value('w.id');

        if ($assigned !== null) {
            return (string) $assigned;
        }

        // Fallback: la empresa puede tener bodegas activas no asignadas a esta
        // sede (caso 2+ bodegas, sede sin asignación). Para no perder la receta
        // histórica, se backfillea con la primera bodega activa de la empresa.
        $fallback = DB::table('warehouses')
            ->where('company_nit', $companyNit)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->value('id');

        return $fallback !== null ? (string) $fallback : null;
    }
};
