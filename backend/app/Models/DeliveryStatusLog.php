<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Log append-only de transiciones de estado de delivery.
 *
 * Cada fila representa un cambio de status escrito desde `DeliveryService`.
 * Se mantiene en su propia tabla en vez de solo `audit_logs` para que la
 * historia de un delivery sea consultable con un único índice O(1) por
 * `delivery_id`. NO admite mutaciones: sin updated_at, sin deletes;
 * cualquier corrección se hace agregando una fila nueva.
 *
 * @property int $id
 * @property string $company_nit
 * @property string $branch_id
 * @property int $delivery_id
 * @property string $from_status
 * @property string $to_status
 * @property ?string $reason — error_usuario | pedido_rechazado | reassigned | null
 * @property ?int $actor_id — User que ejecutó la transición (admin o courier)
 * @property Carbon $created_at
 */
class DeliveryStatusLog extends Model
{
    use BelongsToBranch, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'company_nit',
        'branch_id',
        'delivery_id',
        'from_status',
        'to_status',
        'reason',
        'actor_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
