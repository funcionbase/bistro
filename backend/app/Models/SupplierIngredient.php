<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivote proveedor↔insumo. Cachea el último costo unitario NETO conocido y
 * cuándo se compró por última vez. Mutado por PurchaseService::receive().
 *
 * @property int $supplier_id
 * @property int $ingredient_id
 * @property string $last_unit_cost
 * @property Carbon|null $last_purchased_at
 */
class SupplierIngredient extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'supplier_id',
        'ingredient_id',
        'last_unit_cost',
        'last_purchased_at',
    ];

    protected function casts(): array
    {
        return [
            'last_unit_cost' => 'decimal:2',
            'last_purchased_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
