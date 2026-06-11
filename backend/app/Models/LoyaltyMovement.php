<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Movimiento append-only sobre una cuenta de fidelización (#122).
 *
 * Toda variación de balance debe materializarse aquí. NUNCA actualizar este
 * registro tras su creación: errores se corrigen con un movement adicional
 * type=adjust con signo opuesto. Cumple la regla contable de CLAUDE.md.
 *
 * Tipos:
 *  - earn           — otorgado al cerrar una orden completed. UNIQUE PARCIAL
 *                     sobre (reference_type='order', reference_id, type='earn')
 *                     hace idempotente el award.
 *  - redeem         — descuento por canje. Vinculado a un Coupon vía loyalty_redemptions.
 *  - refund_reverse — reversa automática cuando se devuelve la orden que lo originó.
 *  - adjust         — ajuste manual del staff (positivo o negativo). Requiere actor_id.
 *  - expire         — expiración masiva por inactividad (job loyalty:expire-stale).
 *
 * @property int $id
 * @property int $loyalty_account_id
 * @property string $company_nit
 * @property string $type
 * @property int $points
 * @property ?string $reference_type
 * @property ?string $reference_id
 * @property ?int $actor_id
 * @property ?array<string, mixed> $meta
 * @property Carbon $created_at
 */
class LoyaltyMovement extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_EARN = 'earn';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_REFUND_REVERSE = 'refund_reverse';

    public const TYPE_ADJUST = 'adjust';

    public const TYPE_EXPIRE = 'expire';

    /** @var list<string> */
    protected $fillable = [
        'loyalty_account_id',
        'company_nit',
        'type',
        'points',
        'reference_type',
        'reference_id',
        'actor_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LoyaltyAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
