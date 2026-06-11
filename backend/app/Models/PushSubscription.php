<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Suscripción Web Push de un dispositivo del usuario (#149).
 *
 * Una sub representa el par (user, dispositivo+navegador). El navegador
 * genera un `endpoint` único (FCM / Mozilla / Apple) y dos claves
 * (`p256dh`, `auth`) que el backend usa con `minishlink/web-push` para
 * cifrar y firmar el payload.
 *
 * NO usa el trait `BelongsToBranch`: una sub es por user, no por sede. El
 * sistema decide a quién enviar push según permisos operativos al momento
 * del despacho (`orders.update`, `reports.read`, etc.), no según la sede
 * asociada a la sub.
 *
 * @property int $id
 * @property int $user_id
 * @property string $company_nit
 * @property string|null $branch_id — uuid
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 * @property string|null $user_agent
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PushSubscription extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'company_nit',
        'branch_id',
        'endpoint',
        'p256dh',
        'auth',
        'user_agent',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** @param Builder<PushSubscription> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** @param Builder<PushSubscription> $query */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }
}
