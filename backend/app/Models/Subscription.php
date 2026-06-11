<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Suscripción activa de una empresa a un plan de facturación.
 *
 * Estados: active | paused | cancelled.
 * Una empresa puede tener solo una suscripción activa a la vez.
 * La suscripción activa se usa por BillingService para generar facturas mensuales.
 *
 * @property string $status — active | paused | cancelled
 * @property string $company_nit — FK a companies.nit
 */
class Subscription extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'billing_plan_id',
        'plan_name_snapshot',
        'plan_price_snapshot',
        'plan_features_snapshot',
        'plan_tax_regime_snapshot',
        'plan_tax_rate_snapshot',
        'plan_snapshot_at',
        'status',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'cancelled_at' => 'datetime',
            'plan_snapshot_at' => 'datetime',
            'plan_price_snapshot' => 'decimal:2',
            'plan_tax_rate_snapshot' => 'decimal:2',
            'plan_features_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<BillingPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @param Builder<Subscription> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
