<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Recipe;
use App\Support\UnitConverter;
use Illuminate\Support\Collection;

/**
 * Calcula el costo unitario de un ítem de menú a partir de su receta (BOM).
 *
 * Costeo por sede + por bodega (#costeo-multibodega):
 *  - Se filtra explícitamente por `branch_id`. La receta pertenece al menú de
 *    UNA sede; sin este filtro (p.ej. en el cron de snapshots, sin
 *    `active_branch_id`) la query traería recetas de todas las sedes que
 *    compartan `menu_item_id` (cartas clonadas) e inflaría el costo.
 *  - El costo unitario de cada línea sale de `ingredient_stocks.current_cost`
 *    de la **bodega de la línea** (`recipe.warehouse_id`), no de un costo único
 *    del insumo (que ya no existe).
 *  - Si no hay fila de stock para ese (insumo, bodega), la línea entra al
 *    breakdown con costo 0 y `misconfigured = true` — permite a la UI marcar
 *    recetas mal configuradas sin romper el cálculo.
 *  - La cantidad se normaliza a la unidad del insumo vía `UnitConverter` y la
 *    suma se hace con bcmath en 2 decimales (invariante contable COP).
 */
final class RecipeCostService
{
    /**
     * @return array{
     *   total_cost: string,
     *   breakdown: list<array{recipe_id:string, ingredient_id:string, ingredient_name:string, ingredient_unit:string, warehouse_id:string, recipe_quantity:string, recipe_unit:string, normalized_quantity:string, unit_cost:string, line_cost:string, misconfigured:bool}>
     * }
     */
    public function compute(string $companyNit, string $branchId, string $menuItemId): array
    {
        // withoutBranchScope + where branch_id: determinista en HTTP y en cron
        // (el cron no tiene active_branch_id; sin esto el BranchScope no
        // aplicaría y traería recetas cross-sede).
        $rows = Recipe::withoutBranchScope()
            ->forCompany($companyNit)
            ->where('branch_id', $branchId)
            ->active()
            ->forMenuItem($menuItemId)
            ->with('ingredient')
            ->get();

        $costMap = $this->costMapForRecipes($rows);

        $total = '0.00';
        $breakdown = [];

        foreach ($rows as $row) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $row->ingredient;
            if (! $ingredient) {
                continue;
            }

            $normalized = UnitConverter::convert((string) $row->quantity, $row->unit, $ingredient->unit);

            $key = $row->ingredient_id.'|'.$row->warehouse_id;
            $hasCost = array_key_exists($key, $costMap);
            $unitCost = $hasCost ? $costMap[$key] : '0.00';

            // Multiplicación a 6 dec, redondeo final a 2 (centavos).
            $lineCost6 = bcmul($normalized, $unitCost, 6);
            $lineCost = bcadd($lineCost6, '0', 2);

            $total = bcadd($total, $lineCost, 2);

            $breakdown[] = [
                // recipes.id e ingredients.id son uuid → string, no int.
                'recipe_id' => (string) $row->id,
                'ingredient_id' => (string) $ingredient->id,
                'ingredient_name' => (string) $ingredient->name,
                'ingredient_unit' => (string) $ingredient->unit,
                'warehouse_id' => (string) $row->warehouse_id,
                'recipe_quantity' => (string) $row->quantity,
                'recipe_unit' => (string) $row->unit,
                'normalized_quantity' => $normalized,
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
                'misconfigured' => ! $hasCost,
            ];
        }

        return [
            'total_cost' => $total,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * `true` si el ítem tiene al menos una línea activa de receta en la sede.
     */
    public function hasRecipe(string $companyNit, string $branchId, string $menuItemId): bool
    {
        return Recipe::withoutBranchScope()
            ->forCompany($companyNit)
            ->where('branch_id', $branchId)
            ->active()
            ->forMenuItem($menuItemId)
            ->exists();
    }

    /**
     * Mapa (ingredient_id|warehouse_id) → WAC de la bodega, para las líneas
     * dadas. Una sola query a `ingredient_stocks`.
     *
     * @param  Collection<int, Recipe>  $rows
     * @return array<string, string>
     */
    private function costMapForRecipes($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        // Pares exactos (insumo, bodega) de las líneas — no el producto cartesiano
        // de ambos `whereIn` (que traería filas de stock que nunca se consultan).
        $pairs = $rows
            ->map(fn ($r) => ['ingredient_id' => $r->ingredient_id, 'warehouse_id' => $r->warehouse_id])
            ->unique(fn ($p) => $p['ingredient_id'].'|'.$p['warehouse_id'])
            ->values();

        $stockRows = IngredientStock::query()
            ->where(function ($query) use ($pairs) {
                foreach ($pairs as $pair) {
                    $query->orWhere(function ($inner) use ($pair) {
                        $inner->where('ingredient_id', $pair['ingredient_id'])
                            ->where('warehouse_id', $pair['warehouse_id']);
                    });
                }
            })
            ->get(['ingredient_id', 'warehouse_id', 'current_cost']);

        $map = [];
        foreach ($stockRows as $stock) {
            $map[$stock->ingredient_id.'|'.$stock->warehouse_id] = (string) $stock->current_cost;
        }

        return $map;
    }
}
