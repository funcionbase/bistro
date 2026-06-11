<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Insumo de inventario: catálogo de EMPRESA (#costeo-multibodega).
 *
 * Antes el insumo era por-sede (`ingredients.branch_id` + WAC global en
 * `current_cost`). Ahora es company-wide: una sola identidad por empresa,
 * compartida entre todas las sedes. El stock vive en `ingredient_stocks` por
 * (ingredient, warehouse) y el **WAC vive por bodega** en
 * `ingredient_stocks.current_cost` — el insumo ya no tiene un costo único.
 *
 * El stock total del insumo en una sede se obtiene sumando los stocks de las
 * bodegas asignadas a esa sede (vía pivot `branch_warehouses`).
 *
 * @property string $id — uuid
 * @property string $company_nit
 * @property string $name — único por empresa
 * @property string|null $category
 * @property string $unit — kg|g|l|ml|un
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Ingredient extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'name',
        'category',
        'unit',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<IngredientMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(IngredientMovement::class);
    }

    /** @return HasMany<IngredientStock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(IngredientStock::class);
    }

    public function scopeForCompany(Builder $q, string $nit): Builder
    {
        return $q->where('company_nit', $nit);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function scopeArchived(Builder $q): Builder
    {
        return $q->whereNotNull('archived_at');
    }
}
