<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de cancelación de un order_item ya aprobado.
 *
 * Se crea cuando el cliente intenta cancelar un item con status `approved`
 * (post-aprobación del mesero, pre-cocina). El mesero ve la solicitud en su
 * pantalla y decide aprobar/negar. Si aprueba, el item pasa a `cancelled`
 * con `cancellation_reason='waiter_approved'`.
 *
 * Items en `in_kitchen` o posterior NO pueden generar `CancellationRequest`:
 * el cliente debe hablar con el mesero, quien usa una acción manual aparte.
 *
 * @property int $id
 * @property int $order_item_id
 * @property int $guest_id
 * @property string $status
 * @property string|null $reason
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_user_id
 */
class CancellationRequest extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'order_item_id',
        'guest_id',
        'status',
        'reason',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /** @return BelongsTo<TableSessionGuest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(TableSessionGuest::class, 'guest_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /** @param Builder<CancellationRequest> $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
