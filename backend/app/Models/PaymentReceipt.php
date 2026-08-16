<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\PaymentReceiptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comprobante de pago/devolución asociado a una orden.
 *
 * Columnas estructuradas (fuente de verdad contable):
 *  - payment_method : cash | card | transfer | refund
 *  - amount         : decimal:2 con SIGNO (cobro positivo, devolución negativo).
 *                     Net por método = SUM(amount) GROUP BY payment_method.
 *  - reference      : número de comprobante (datáfono/transferencia)
 *  - paid_at        : timestamp del cobro/devolución
 *
 * file_path es opcional (solo cuando el cliente sube comprobante visual via bot/cart).
 * payment_data se conserva como detalle complementario (cambio devuelto, razón,
 * original_method en refunds, etc.) — debe mantenerse coherente con las columnas.
 *
 * Sin updated_at ($timestamps=false). Estos registros son inmutables: para corregir
 * un cobro mal registrado, se crea otro receipt (refund) en lugar de mutar el original.
 */
class PaymentReceipt extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<PaymentReceiptFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'guest_id',
        'company_nit',
        'client_uuid',
        'file_path',
        'payment_method',
        'amount',
        'reference',
        'paid_at',
        'occurred_at_client',
        'cash_session_id',
        'payment_data',
    ];

    protected function casts(): array
    {
        return [
            'payment_data' => 'array',
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
            'paid_at' => 'datetime',
            'occurred_at_client' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /**
     * Comensal específico asociado al pago (pago dividido).
     *
     * Nullable: en pago completo o en cobros que no provienen de mesa con QR,
     * el receipt no se asocia a un guest particular.
     *
     * @return BelongsTo<TableSessionGuest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(TableSessionGuest::class, 'guest_id');
    }
}
