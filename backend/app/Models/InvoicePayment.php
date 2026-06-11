<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro inmutable de un pago aplicado a una factura.
 *
 * Sin timestamps de actualización ($timestamps=false). Los pagos no deben eliminarse ni modificarse.
 * Cada pago referencia la factura y la empresa para facilitar consultas de auditoría.
 *
 * @property string $payment_reference — número de referencia del pago externo
 * @property string $payment_method — transferencia | efectivo | qr | etc.
 */
class InvoicePayment extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'company_nit',
        'amount',
        'currency',
        'payment_reference',
        'payment_date',
        'payment_method',
        'registered_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
