<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cabecera de orden de compra a proveedor.
 *
 * Estados: draft|pending|received|paid|cancelled|voided.
 * Las transiciones válidas viven en `config('purchases.transitions')`.
 *
 * `pending_supplier_refund` se levanta al anular una PO ya `paid`: el dinero
 * salió, y queda pendiente la devolución del proveedor.
 *
 * @property int $id
 * @property string $company_nit
 * @property int $supplier_id
 * @property string $code
 * @property string $status
 * @property Carbon|null $expected_date
 * @property Carbon|null $received_date
 * @property Carbon|null $paid_date
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $total
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property bool $pending_supplier_refund
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $received_by
 * @property int|null $paid_by
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 */
class PurchaseOrder extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'supplier_id',
        'code',
        'status',
        'expected_date',
        'received_date',
        'paid_date',
        'subtotal',
        'tax_amount',
        'total',
        'payment_method',
        'payment_reference',
        'pending_supplier_refund',
        'notes',
        'created_by',
        'received_by',
        'paid_by',
        'voided_by',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_date' => 'date',
            'received_date' => 'datetime',
            'paid_date' => 'datetime',
            'voided_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'pending_supplier_refund' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** @return HasMany<PurchaseCreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(PurchaseCreditNote::class);
    }

    /** @return HasMany<PurchaseOrderAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(PurchaseOrderAttachment::class)->whereNull('deleted_at');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeForCompany(Builder $q, string $nit): Builder
    {
        return $q->where('company_nit', $nit);
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['cancelled', 'voided'], true);
    }
}
