<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Canje de puntos por descuento.
 *
 * Vincula un movement(type=redeem) con un Coupon single-use generado al canjear.
 * El cupón es de uso único y expira en config('loyalty.redemption_expires_minutes')
 * minutos. Cuando se aplica a una orden, status pasa a 'applied' y applied_order_id
 * queda fijo (inmutable después de aplicarse).
 *
 * Status:
 *  - issued    — cupón creado, sin usar.
 *  - applied   — el cupón se vinculó a una orden completada/aceptada.
 *  - expired   — pasó expires_at sin usarse. Los puntos NO se devuelven (regla
 *                contable: el canje ya consumió los puntos al crearse).
 *  - cancelled — staff o sistema canceló antes de aplicarse. Sí devuelve puntos
 *                vía movement adjust positivo equivalente.
 *
 * @property int $id
 * @property int $loyalty_account_id
 * @property int $loyalty_movement_id
 * @property ?int $coupon_id
 * @property string $reward_key
 * @property int $points
 * @property string $status
 * @property Carbon $expires_at
 * @property ?Carbon $applied_at
 * @property ?int $applied_order_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class LoyaltyRedemption extends Model
{
    use HasUuids;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'loyalty_account_id',
        'loyalty_movement_id',
        'coupon_id',
        'reward_key',
        'points',
        'status',
        'expires_at',
        'applied_at',
        'applied_order_id',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'expires_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LoyaltyAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    /** @return BelongsTo<LoyaltyMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(LoyaltyMovement::class, 'loyalty_movement_id');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function appliedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'applied_order_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED)->where('expires_at', '>', now());
    }
}
