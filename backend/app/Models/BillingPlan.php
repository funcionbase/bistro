<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * Plan de facturación disponible en la plataforma.
 *
 * El campo features es un JSONB con las capacidades incluidas en el plan.
 * slug es único y se usa como referencia estable en configuraciones y código.
 *
 * @property array<string, mixed> $features — JSONB con capacidades y límites del plan
 * @property string $billing_cycle — monthly | yearly
 */
class BillingPlan extends Model
{
    use HasUuids;

    private const CACHE_KEY_ACTIVE = 'billing_plans.active';

    private const CACHE_TTL = 3600; // 1h — rara vez cambian

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'billing_cycle',
        'features',
        'is_active',
        'is_default',
        'price_includes_tax',
        'tax_regime',
        'tax_rate',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'price_includes_tax' => 'boolean',
            'tax_rate' => 'decimal:2',
        ];
    }

    /**
     * Devuelve el plan default vigente (`is_default=true AND is_active=true`).
     * Cache de 1h con invalidación automática en `saved`/`deleted`.
     */
    public static function default(): ?self
    {
        return Cache::remember(
            'billing_plans.default',
            self::CACHE_TTL,
            fn () => self::query()->where('is_default', true)->where('is_active', true)->first(),
        );
    }

    protected static function booted(): void
    {
        // Invalida caché al modificar/crear/eliminar un plan — los planes
        // cambian rara vez pero cuando lo hacen, la propagación debe ser inmediata.
        static::saved(function (): void {
            Cache::forget(self::CACHE_KEY_ACTIVE);
            Cache::forget('billing_plans.default');
        });
        static::deleted(function (): void {
            Cache::forget(self::CACHE_KEY_ACTIVE);
            Cache::forget('billing_plans.default');
        });
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Lista cacheada de planes activos ordenada por sort_order.
     *
     * Usada en /pricing, checkout y validación de planes — se consulta en
     * cada request de billing. Cache 1h con invalidación automática en
     * `saved`/`deleted`.
     *
     * @return Collection<int, BillingPlan>
     */
    public static function activeCached(): Collection
    {
        return Cache::remember(
            self::CACHE_KEY_ACTIVE,
            self::CACHE_TTL,
            fn () => self::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        );
    }
}
