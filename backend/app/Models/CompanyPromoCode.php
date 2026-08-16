<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aplicación de un promo code a una empresa específica.
 *
 * Tabla puente con snapshot inmutable: `discount_percent` y `months_duration`
 * se congelan al aplicar. Si el `PromoCode` original cambia o se desactiva,
 * esta fila conserva los términos originales (auditoría DIAN).
 *
 * `starts_at` se decide al aplicar según el vector (enrollment / GH Action /
 * self_service) y respeta `companies.paid_billing_starts_at` (trial activo).
 *
 * Solo 1 fila puede tener `status='active'` por empresa (UNIQUE parcial DB).
 *
 * Estados:
 *  - `active`: vigente, aplica descuento a invoices generadas en el período.
 *  - `expired`: el cron diario marcó como vencido (ends_at < now()).
 *  - `cancelled`: cancelado manualmente (self-service o GH Action).
 *
 * @property string $applied_via — enrollment | github_action | self_service
 */
class CompanyPromoCode extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'promo_code_id',
        'discount_percent',
        'months_duration',
        'starts_at',
        'ends_at',
        'status',
        'applied_via',
        'applied_by',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'discount_percent' => 'integer',
            'months_duration' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<PromoCode, $this> */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /** @return BelongsTo<User, $this> */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'company_promo_code_id');
    }

    /** @param  Builder<CompanyPromoCode>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /**
     * Scope: vigentes al momento `$date` (status active + dentro del período).
     * Usado por BillingService para resolver el descuento aplicable al mes.
     *
     * @param  Builder<CompanyPromoCode>  $query
     */
    public function scopeActiveForDate(Builder $query, CarbonInterface $date): void
    {
        $query->where('status', 'active')
            ->where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date);
    }

    public function isVigentAt(CarbonInterface $date): bool
    {
        return $this->status === 'active'
            && $this->starts_at <= $date
            && $this->ends_at >= $date;
    }
}
