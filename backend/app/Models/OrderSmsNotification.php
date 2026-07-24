<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Registro de un SMS al cliente disparado por un cambio de estado de orden
 * (#275). Ancla de deduplicación N-instance-safe: UNIQUE(order_id, to_status).
 *
 * Ciclo del campo `status`: queued (registrado dentro de la transacción del
 * cambio de estado) → sent | failed (resuelto por SendOrderStatusSmsJob).
 *
 * @property string $order_id
 * @property string $company_nit
 * @property string $branch_id
 * @property string $to_status — estado destino notificable (config order_notifications)
 * @property string $channel — sms | whatsapp (canal efectivo de la notificación)
 * @property string $phone — destino en E.164
 * @property string $status — queued | sent | failed
 * @property ?string $provider_message_id
 * @property ?int $segments
 * @property ?string $error
 * @property ?string $chat_message_id
 * @property ?Carbon $sent_at
 */
class OrderSmsNotification extends Model
{
    use BelongsToBranch;
    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'company_nit',
        'branch_id',
        'to_status',
        'channel',
        'phone',
        'user_id',
        'status',
        'provider_message_id',
        'segments',
        'error',
        'chat_message_id',
        'sent_at',
        'user_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'integer',
            'sent_at' => 'datetime',
            'user_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
