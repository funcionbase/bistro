<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cuenta de fidelización de un cliente (#122).
 *
 * Identidad: (company_nit, client_phone). Cross-sede por diseño — no usa
 * BelongsToBranch. Phone debe estar normalizado al formato 57XXXXXXXXXX
 * por CrmService::normalizePhone() antes de persistir/buscar.
 *
 * Inmutabilidad/transaccional: las mutaciones a balance/lifetime_earned/tier
 * SIEMPRE deben hacerse vía LoyaltyService dentro de DB::transaction con
 * $account->lockForUpdate(). Nunca actualizar balance directamente desde
 * controllers o jobs.
 *
 * @property int $id
 * @property string $company_nit
 * @property string $client_phone
 * @property int $balance
 * @property int $lifetime_earned
 * @property string $tier
 * @property ?Carbon $last_activity_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class LoyaltyAccount extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'client_phone',
        'balance',
        'lifetime_earned',
        'tier',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'lifetime_earned' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<LoyaltyMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(LoyaltyMovement::class);
    }

    /** @return HasMany<LoyaltyRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class);
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeForClient(Builder $query, string $companyNit, string $clientPhone): Builder
    {
        return $query->where('company_nit', $companyNit)->where('client_phone', $clientPhone);
    }
}
