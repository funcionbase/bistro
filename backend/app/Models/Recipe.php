<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Línea de receta (BOM) por ítem de menú.
 *
 * Indica cuánto de un `Ingredient` se consume por unidad vendida del ítem
 * identificado por `menu_item_id` (UUID dentro de `RestaurantMenu.structure`).
 *
 * Mutación: PUT del set completo a través de `RecipeController::upsert`. Las
 * filas viejas se soft-archive (`archived_at`) y se insertan las nuevas, en
 * una transacción. El índice único parcial garantiza una sola fila activa
 * por (empresa, item, insumo).
 *
 * @property int $id
 * @property string $company_nit
 * @property int $menu_id
 * @property string $menu_item_id
 * @property int $ingredient_id
 * @property string $quantity — decimal:3
 * @property string $unit — kg|g|l|ml|un (compatible con $ingredient->unit)
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Recipe extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'menu_id',
        'menu_item_id',
        'ingredient_id',
        'warehouse_id',
        'quantity',
        'unit',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<RestaurantMenu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(RestaurantMenu::class, 'menu_id');
    }

    public function scopeForCompany(Builder $q, string $nit): Builder
    {
        return $q->where('company_nit', $nit);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function scopeForMenuItem(Builder $q, string $menuItemId): Builder
    {
        return $q->where('menu_item_id', $menuItemId);
    }

    /**
     * Devuelve el snapshot del item dentro del JSON `RestaurantMenu.structure`.
     *
     * Antes recorría categorías y items linealmente por cada lookup (O(N×M)).
     * Ahora delega a `RestaurantMenu::findMenuItem()` que usa un índice
     * memoizado (O(1) tras la primera llamada por instancia del menú).
     *
     * @return array<string, mixed>|null
     */
    public function resolveMenuItem(): ?array
    {
        return $this->menu()->first()?->findMenuItem($this->menu_item_id);
    }
}
