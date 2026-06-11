<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stock de un insumo en una bodega específica.
 *
 * Fuente única de verdad del inventario por bodega. Reemplaza la
 * denormalización antigua en `ingredients.current_stock`.
 *
 * Reglas:
 *  - Una fila por (ingredient_id, warehouse_id). El `quantity` puede ser 0
 *    si el insumo está disponible en la bodega pero sin stock; el
 *    InventoryService crea la fila on-demand al primer movimiento.
 *  - `min_stock` por bodega permite alertas granulares (ej. queso bajo en
 *    cocina caliente, alto en cold_storage).
 *  - `current_cost` es el **WAC (promedio ponderado) por bodega**
 *    (#costeo-multibodega): se recalcula en cada `entry` sobre el stock de
 *    ESTA bodega, y las transferencias trasladan valor (la bodega destino
 *    mezcla su WAC con el costo entrante = WAC de la bodega origen). decimal:2.
 *  - No tiene `created_at` (auto-poblada al `firstOrCreate`); sólo
 *    `updated_at` para auditoría del último cambio.
 *  - No es append-only: se UPDATE por cada movimiento (con lockForUpdate).
 *    La bitácora append-only vive en `ingredient_movements`.
 *
 * @property string $id — uuid
 * @property string $ingredient_id — uuid
 * @property string $warehouse_id — uuid
 * @property string $quantity — decimal:3
 * @property string $min_stock — decimal:3
 * @property string $current_cost — WAC de la bodega, decimal:2
 * @property Carbon $updated_at
 */
class IngredientStock extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'ingredient_id',
        'warehouse_id',
        'quantity',
        'min_stock',
        'current_cost',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'current_cost' => 'decimal:2',
            'updated_at' => 'datetime',
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

    /**
     * Stocks por debajo del mínimo configurado. min_stock=0 se interpreta
     * como "sin alerta" — no se incluye aunque quantity sea 0.
     *
     * @param  Builder<IngredientStock>  $query
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->where('min_stock', '>', 0)
            ->whereColumn('quantity', '<', 'min_stock');
    }
}
