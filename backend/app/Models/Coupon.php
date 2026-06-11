<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Services\CrmService;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Cupón de descuento de empresa. Sus condiciones son inmutables después del primer uso.
 *
 * SoftDeletes: los cupones eliminados no se muestran pero sus redemptions se conservan para auditoría.
 * Inmutabilidad: un cupón con uses_count > 0 no debe ser editado; el controlador lo rechaza con 422.
 * first_order_only: validado contra el historial de pedidos del client_phone (config coupons.validation.enable_first_order_check).
 * Cuando uses_count alcanza max_uses, el status se actualiza automáticamente a 'exhausted'.
 *
 * @property string $type — percentage | fixed
 * @property string $status — active | inactive | exhausted
 */
class Coupon extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<CouponFactory> */
    use HasFactory, SoftDeletes;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'scope',
        'valid_in_branches',
        'code',
        'type',
        'value',
        'valid_from',
        'valid_until',
        'valid_days',
        'valid_hours_from',
        'valid_hours_to',
        'auto_apply',
        'max_uses',
        'uses_count',
        'min_order_amount',
        'first_order_only',
        'is_active',
        'status',
        'created_by',
        'is_single_use',
        'locked_to_phone',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'first_order_only' => 'boolean',
            'is_active' => 'boolean',
            'is_single_use' => 'boolean',
            'valid_in_branches' => 'array',
            'valid_days' => 'array',
            'auto_apply' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** @param Builder<Coupon> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** @param Builder<Coupon> $query */
    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    /** @param Builder<Coupon> $query */
    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
        });
    }

    /** @param Builder<Coupon> $query */
    public function scopeNotExhausted(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('max_uses')->orWhereColumn('uses_count', '<', 'max_uses');
        });
    }

    public function calculateDiscount(float $totalAmount): float
    {
        if ($this->type === 'percentage') {
            $discount = (float) $this->value / 100 * $totalAmount;

            return ceil($discount * 100) / 100;
        }

        return min((float) $this->value, $totalAmount);
    }

    /**
     * @return array{valid: bool, error: string|null}
     */
    public function isValidFor(float $totalAmount, ?string $clientPhone): array
    {
        if ($this->status !== 'active') {
            $label = $this->status === 'exhausted' ? 'agotado' : 'inactivo';

            return ['valid' => false, 'error' => "Cupón {$label}"];
        }

        if ($this->valid_from && now()->lt($this->valid_from)) {
            return ['valid' => false, 'error' => 'Cupón no vigente aún (válido desde '.$this->valid_from->format('d/m/Y').')'];
        }

        if ($this->valid_until && now()->gt($this->valid_until)) {
            return ['valid' => false, 'error' => 'Cupón vencido (expiró el '.$this->valid_until->format('d/m/Y').')'];
        }

        if ($this->max_uses !== null && $this->uses_count >= $this->max_uses) {
            return ['valid' => false, 'error' => "Cupón agotado (máximo {$this->max_uses} usos)"];
        }

        if ($totalAmount < (float) $this->min_order_amount) {
            $min = number_format((float) $this->min_order_amount, 0, ',', '.');

            return ['valid' => false, 'error' => "Cupón requiere monto mínimo de \${$min}"];
        }

        // Cupón de canje de fidelización (#122): solo aplicable si el client_phone
        // del checkout coincide con el que canjeó. Evita compartir códigos LYL-*.
        if ($this->locked_to_phone !== null) {
            $normalizedRequest = $clientPhone ? CrmService::normalizePhone($clientPhone) : '';
            if ($normalizedRequest === '' || $normalizedRequest !== $this->locked_to_phone) {
                return ['valid' => false, 'error' => 'Este cupón solo puede ser usado por el cliente que lo canjeó.'];
            }
        }

        // is_single_use es defensivo además del max_uses=1: si por alguna razón
        // max_uses no se respetó, esto bloquea cualquier reuso.
        if ($this->is_single_use && $this->uses_count >= 1) {
            return ['valid' => false, 'error' => 'Este cupón ya fue usado.'];
        }

        if (! $this->isScheduledNow()) {
            return ['valid' => false, 'error' => 'Cupón fuera de horario'];
        }

        if ($this->first_order_only && $clientPhone && config('coupons.validation.enable_first_order_check', true)) {
            $hasOrders = Order::where('client_phone', $clientPhone)
                ->where('company_nit', $this->company_nit)
                ->exists();

            if ($hasOrders) {
                return ['valid' => false, 'error' => 'Cupón solo válido para primer pedido'];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Evalúa si el cupón está dentro de su franja horaria programada (#125).
     * Sin programación (valid_days NULL/[] y horas NULL) => siempre válido.
     * Días: array de ints 0-6 (0=domingo). Horas en America/Bogota.
     * Cross-midnight: si from > to (ej. 22:00→02:00), la ventana cruza medianoche
     * y se considera activa si now >= from o now <= to.
     */
    public function isScheduledNow(?Carbon $now = null): bool
    {
        $now ??= Carbon::now('America/Bogota');
        if ($now->timezone->getName() !== 'America/Bogota') {
            $now = $now->copy()->setTimezone('America/Bogota');
        }

        $days = $this->valid_days;
        if (is_array($days) && count($days) > 0) {
            if (! in_array((int) $now->format('w'), array_map('intval', $days), true)) {
                return false;
            }
        }

        $from = $this->valid_hours_from;
        $to = $this->valid_hours_to;
        if ($from === null || $to === null) {
            return true;
        }

        $current = $now->format('H:i:s');
        $fromStr = substr((string) $from, 0, 8);
        $toStr = substr((string) $to, 0, 8);

        if ($fromStr <= $toStr) {
            return $current >= $fromStr && $current <= $toStr;
        }

        // Ventana cruza medianoche.
        return $current >= $fromStr || $current <= $toStr;
    }

    public function incrementUsage(): void
    {
        $this->increment('uses_count');
        $this->refresh();

        if ($this->max_uses !== null && $this->uses_count >= $this->max_uses) {
            $this->update(['status' => 'exhausted']);
        }
    }
}
