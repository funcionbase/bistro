<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Movimiento de inventario (append-only).
 *
 * Tipos: entry | adjustment | sale_consumption | waste | transfer.
 * `quantity` es signed: + ingresa, − sale.
 * `unit_cost` solo en `entry` (alimenta el promedio ponderado).
 *
 * Las correcciones se hacen con un nuevo movimiento `adjustment` opuesto;
 * jamás UPDATE/DELETE — el guard de boot lanza excepción en runtime.
 *
 * @property int $id
 * @property string $company_nit
 * @property int $ingredient_id
 * @property string $type
 * @property string $quantity — signed
 * @property string|null $unit_cost
 * @property string|null $reference
 * @property int|null $actor_id
 * @property Carbon $created_at
 */
class IngredientMovement extends Model
{
    use BelongsToBranch, HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'ingredient_id',
        'warehouse_id',
        'dest_warehouse_id',
        'type',
        'quantity',
        'unit_cost',
        'reference',
        'actor_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'dest_warehouse_id');
    }

    /**
     * Append-only enforcement. La auditoría DIAN exige que la bitácora de
     * movimientos no se altere: cualquier corrección debe ir como un nuevo
     * `adjustment`. Estos guards corren incluso si alguien intenta `update()`
     * o `delete()` directamente desde código nuevo o desde tinker.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('IngredientMovement is append-only — register an adjustment movement instead of updating.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('IngredientMovement is append-only — register an adjustment movement instead of deleting.');
        });
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    public function scopeForCompany(Builder $q, string $nit): Builder
    {
        return $q->where('company_nit', $nit);
    }

    public function scopeForIngredient(Builder $q, string $ingredientId): Builder
    {
        return $q->where('ingredient_id', $ingredientId);
    }
}
