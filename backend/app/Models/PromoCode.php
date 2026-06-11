<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catálogo de promo codes administrables — #246.
 *
 * Slug `code` único globalmente. Vigencia (`starts_at`..`ends_at`) define
 * cuándo el código puede aplicarse a nuevas empresas. `max_companies` limita
 * el total de aplicaciones aceptadas (NULL = ilimitado).
 *
 * Gestión: solo via GitHub Action `company-ops.yml` (ops: create_promo_code,
 * toggle_promo_code). Sin UI en panel.
 *
 * @property string $code — slug URL-amigable (`BLACKFRIDAY2026`)
 * @property int $discount_percent — entero 1..100
 * @property int $months_duration — duración del descuento al aplicarse
 * @property int|null $max_companies — null = sin tope
 * @property int $usage_count — denormalizado, incrementado al aplicar
 * @property string $status — active | inactive
 */
class PromoCode extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_percent',
        'months_duration',
        'max_companies',
        'usage_count',
        'starts_at',
        'ends_at',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'discount_percent' => 'integer',
            'months_duration' => 'integer',
            'max_companies' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CompanyPromoCode, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(CompanyPromoCode::class);
    }

    /**
     * Scope: códigos disponibles para nuevas aplicaciones (status active +
     * dentro de vigencia + bajo el tope si aplica). No bloquea por
     * usage_count > max_companies — eso se chequea en el service con lock.
     *
     * @param  Builder<PromoCode>  $query
     */
    public function scopeApplicable(Builder $query, ?\Carbon\CarbonInterface $at = null): void
    {
        $now = $at ?? now();

        $query->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    public function hasRemainingCapacity(): bool
    {
        return $this->max_companies === null || $this->usage_count < $this->max_companies;
    }
}
