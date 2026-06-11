<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Inventory\BranchHasNoWarehouseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recipes\UpsertRecipeRequest;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RestaurantMenu;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\MenuPermissionService;
use App\Services\RecipeCostService;
use App\Support\UnitConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRUD del set de recetas (BOM) por ítem de menú.
 *
 * Auth/scope: piggyback en `menu.read`/`menu.update` (no se crean permisos
 * nuevos para no inflar el set). Tenant: validado vía `active_company_nit` y
 * pertenencia del menú/ingrediente a la empresa activa.
 *
 * El endpoint `upsert` reemplaza el set completo en una transacción:
 *  1. Archiva las filas activas previas (auditoría DIAN: nunca DELETE).
 *  2. Inserta las líneas nuevas validando compatibilidad de unidades contra
 *     `ingredient.unit` vía `UnitConverter`.
 *  3. Recalcula y devuelve el costo total + breakdown.
 */
class RecipeController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly MenuPermissionService $menuPermissionService,
        private readonly RecipeCostService $costService,
    ) {}

    public function show(Request $request, string $menuId, string $itemId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'read');

        $companyNit = $request->attributes->get('active_company_nit');
        $menu = $this->resolveMenu($companyNit, $menuId);
        $this->resolveItemOrFail($menu, $itemId);

        return response()->json(['data' => $this->buildResponse($companyNit, $menu, $itemId)]);
    }

    public function cost(Request $request, string $menuId, string $itemId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'read');

        $companyNit = $request->attributes->get('active_company_nit');
        $menu = $this->resolveMenu($companyNit, $menuId);
        $this->resolveItemOrFail($menu, $itemId);

        $cost = $this->costService->compute($companyNit, (string) $menu->branch_id, $itemId);

        return response()->json(['data' => $cost]);
    }

    public function upsert(UpsertRecipeRequest $request, string $menuId, string $itemId): JsonResponse
    {
        $this->menuPermissionService->assertMenuPermission($request, 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $menu = $this->resolveMenu($companyNit, $menuId);
        $this->resolveItemOrFail($menu, $itemId);

        $payloadItems = $request->validated()['items'];

        // Pre-validamos ingredientes y bodegas: deben existir, pertenecer a
        // la empresa/sede activa y estar activos. La unidad de la receta debe
        // ser convertible a la del insumo. Errores se devuelven todos juntos
        // en un solo response sin estado parcial.
        $ingredientIds = collect($payloadItems)->pluck('ingredient_id')->unique()->values()->all();
        $ingredients = Ingredient::forCompany($companyNit)
            ->whereIn('id', $ingredientIds)
            ->get()
            ->keyBy('id');

        $branchId = (string) $request->attributes->get('active_branch_id');
        $defaultWarehouseId = $this->resolveDefaultWarehouseId($companyNit, $branchId);

        $warehouseIds = collect($payloadItems)
            ->pluck('warehouse_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // (#costeo-multibodega) La bodega es company-scoped; debe estar asignada
        // a la sede del menú (pivot branch_warehouses).
        $warehouses = $warehouseIds === []
            ? collect()
            : Warehouse::query()
                ->where('company_nit', $companyNit)
                ->forBranch($branchId)
                ->whereIn('id', $warehouseIds)
                ->whereNull('archived_at')
                ->get()
                ->keyBy('id');

        foreach ($payloadItems as $idx => $line) {
            $ingredient = $ingredients->get($line['ingredient_id']);
            if (! $ingredient) {
                throw ValidationException::withMessages([
                    "items.$idx.ingredient_id" => 'El insumo no existe o no pertenece a la empresa.',
                ]);
            }
            if ($ingredient->archived_at !== null) {
                throw ValidationException::withMessages([
                    "items.$idx.ingredient_id" => "El insumo \"{$ingredient->name}\" está archivado.",
                ]);
            }
            if (! UnitConverter::areCompatible($line['unit'], $ingredient->unit)) {
                throw ValidationException::withMessages([
                    "items.$idx.unit" => "La unidad {$line['unit']} no es convertible a {$ingredient->unit} (insumo: {$ingredient->name}).",
                ]);
            }

            $warehouseId = $line['warehouse_id'] ?? null;
            if ($warehouseId !== null && ! $warehouses->has($warehouseId)) {
                throw ValidationException::withMessages([
                    "items.$idx.warehouse_id" => 'La bodega no existe o no pertenece a la sede activa.',
                ]);
            }
        }

        // Detectar duplicados por ingredient_id en el payload (el índice único
        // parcial ya lo bloquearía a nivel DB, pero respondemos con mensaje claro).
        $seen = [];
        foreach ($payloadItems as $idx => $line) {
            if (isset($seen[$line['ingredient_id']])) {
                throw ValidationException::withMessages([
                    "items.$idx.ingredient_id" => 'Insumo duplicado en la receta.',
                ]);
            }
            $seen[$line['ingredient_id']] = true;
        }

        DB::transaction(function () use ($companyNit, $menu, $itemId, $payloadItems, $defaultWarehouseId, $branchId) {
            // Soft-archive de las filas activas anteriores para mantener
            // trazabilidad histórica (órdenes pasadas pueden referenciar
            // versiones previas de la receta).
            Recipe::forCompany($companyNit)
                ->active()
                ->forMenuItem($itemId)
                ->update(['archived_at' => now()]);

            foreach ($payloadItems as $line) {
                Recipe::create([
                    'company_nit' => $companyNit,
                    'branch_id' => $branchId,
                    'menu_id' => $menu->id,
                    'menu_item_id' => $itemId,
                    'ingredient_id' => $line['ingredient_id'],
                    'warehouse_id' => $line['warehouse_id'] ?? $defaultWarehouseId,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                ]);
            }
        });

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('menu.recipe_upserted', $actor, $menu, [
            'company_nit' => $companyNit,
            'menu_id' => $menu->id,
            'menu_item_id' => $itemId,
            'lines' => count($payloadItems),
        ], $request);

        return response()->json(['data' => $this->buildResponse($companyNit, $menu, $itemId)]);
    }

    private function resolveMenu(string $companyNit, string $menuId): RestaurantMenu
    {
        return RestaurantMenu::where('id', $menuId)->where('company_nit', $companyNit)->firstOrFail();
    }

    /**
     * Devuelve el warehouse_id default de la sede del menú (vía pivot). Bloqueo
     * duro BRANCH_HAS_NO_WAREHOUSE si la sede no tiene bodega asignada —
     * configurar receta sin bodega de costeo no es válido (#costeo-multibodega).
     */
    private function resolveDefaultWarehouseId(string $companyNit, string $branchId): string
    {
        $warehouse = Warehouse::defaultForBranch($branchId);

        if ($warehouse === null) {
            throw new BranchHasNoWarehouseException($branchId);
        }

        return $warehouse->id;
    }

    /** @return array<string, mixed> */
    private function resolveItemOrFail(RestaurantMenu $menu, string $itemId): array
    {
        foreach ($menu->structure['categories'] ?? [] as $category) {
            foreach ($category['items'] ?? [] as $item) {
                if (($item['id'] ?? null) === $itemId) {
                    return $item + ['category' => $category['name'] ?? null];
                }
            }
        }

        abort(404, 'Ítem no encontrado en este menú.');
    }

    /**
     * @return array{
     *   menu_item_id: string,
     *   item: array<string, mixed>,
     *   items: list<array<string, mixed>>,
     *   total_cost: string,
     *   margin_pct: float|null,
     *   low_margin: bool,
     * }
     */
    private function buildResponse(string $companyNit, RestaurantMenu $menu, string $itemId): array
    {
        $item = $this->resolveItemOrFail($menu, $itemId);
        $cost = $this->costService->compute($companyNit, (string) $menu->branch_id, $itemId);

        $price = isset($item['price']) ? (float) $item['price'] : 0.0;
        $totalCost = (float) $cost['total_cost'];
        $marginPct = $price > 0 ? round(($price - $totalCost) / $price, 4) : null;
        $threshold = (float) config('menu.recipe.low_margin_threshold', 0.20);
        $lowMargin = $marginPct !== null && $marginPct < $threshold;

        return [
            'menu_item_id' => $itemId,
            'item' => [
                'id' => $item['id'],
                'name' => $item['name'] ?? null,
                'price' => $price,
                'category' => $item['category'] ?? null,
            ],
            'items' => $cost['breakdown'],
            'total_cost' => $cost['total_cost'],
            'margin_pct' => $marginPct,
            'low_margin' => $lowMargin,
        ];
    }
}
