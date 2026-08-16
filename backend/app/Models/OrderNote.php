<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Nota asociada a una orden.
 *
 * Diferente a `order_items.notes` (individual): estas son notas que aplican
 * a toda la orden o que la cocina debe ver replicadas en cada ticket KDS.
 *
 * Scope:
 *  - `group`: nota grupal del comensal/mesero ("traer todo junto").
 *  - `kitchen_alert`: alerta que se replica en todos los tickets del KDS
 *    para esa orden ("cliente alérgico a maní").
 *
 * @property int $id
 * @property int $order_id
 * @property string $scope
 * @property string $body
 * @property string|null $author_type
 * @property int|null $author_id
 */
class OrderNote extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'scope',
        'body',
        'author_type',
        'author_id',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return MorphTo<Model, $this> */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param Builder<OrderNote> $query */
    public function scopeGroup(Builder $query): Builder
    {
        return $query->where('scope', 'group');
    }

    /** @param Builder<OrderNote> $query */
    public function scopeKitchenAlert(Builder $query): Builder
    {
        return $query->where('scope', 'kitchen_alert');
    }
}
