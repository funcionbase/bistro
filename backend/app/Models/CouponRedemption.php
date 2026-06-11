<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro inmutable de una redención de cupón vinculada a un pedido.
 *
 * Sin timestamps de actualización ($timestamps=false). Los registros no deben eliminarse.
 * Guarda los montos antes y después del descuento para auditoría de impacto del cupón.
 *
 * @property string|null $client_phone — teléfono del cliente que redimió el cupón
 */
class CouponRedemption extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<CouponRedemptionFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'coupon_id',
        'company_nit',
        'order_id',
        'client_phone',
        'discount_amount',
        'order_total_before',
        'order_total_after',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'order_total_before' => 'decimal:2',
            'order_total_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
