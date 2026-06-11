<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Codigo de 6 digitos enviado al owner de la empresa para autorizar acciones
 * sensibles sobre la cuenta de WhatsApp (connect, swap, disconnect).
 *
 * El correo SIEMPRE va al owner aunque quien dispare la accion sea otro usuario
 * con permiso RBAC. Asi el dueno se entera de cualquier cambio.
 *
 * Reglas:
 * - TTL 10 min (expires_at).
 * - Maximo 3 intentos fallidos antes de invalidar.
 * - Rate limit: 3 codigos por empresa cada 30 min.
 *
 * @property string $company_nit
 * @property int $requester_user_id
 * @property int $owner_user_id
 * @property string $action
 * @property string $code_hash
 * @property string $reject_token
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property Carbon $expires_at
 * @property int $attempts
 * @property ?Carbon $consumed_at
 * @property ?Carbon $rejected_at
 */
class WhatsappVerificationCode extends Model
{
    use HasUuids;

    public const ACTIONS = ['connect', 'swap', 'disconnect', 'update'];

    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 3;

    public const RATE_LIMIT_REQUESTS = 3;

    public const RATE_LIMIT_WINDOW_MINUTES = 30;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'requester_user_id',
        'owner_user_id',
        'action',
        'code_hash',
        'reject_token',
        'ip_address',
        'user_agent',
        'expires_at',
        'attempts',
        'consumed_at',
        'rejected_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->whereNull('rejected_at')
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    public function isLockedOut(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}
