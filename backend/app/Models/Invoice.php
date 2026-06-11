<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura de suscripción mensual. Los campos financieros son inmutables tras la creación.
 *
 * Estados válidos: draft | pending | paid | overdue | voided.
 * Los estados 'paid' y 'voided' son terminales: no se puede cambiar su estado desde la API.
 * El modelo verifica inmutabilidad de campos financieros en el evento 'updating' y lanza LogicException.
 * Las notas de crédito se vinculan a la factura original via voided_by_invoice_id.
 * El PDF se genera bajo demanda y su ruta se guarda en pdf_path.
 *
 * @property string $status — draft | pending | paid | overdue | voided
 * @property string $pdf_path — ruta en disco del PDF generado (invoices/{id}.pdf)
 */
class Invoice extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'subscription_id',
        'plan_name_snapshot',
        'plan_price_snapshot',
        'plan_snapshot_at',
        'company_promo_code_id',
        'electronic_document_id',
        'type',
        'period_from',
        'period_to',
        'days_billed',
        'base_amount',
        'base_amount_taxable',
        'discount_percent',
        'discount_amount',
        'tax_amount',
        'tax_rate',
        'tax_regime',
        'amount',
        'currency',
        'due_date',
        'status',
        'voided_by_invoice_id',
        'pdf_path',
        'pdf_generated_at',
        // Markers de notificaciones email (at-most-once cross-instance,
        // gestionado por BillingService::notifyOnce).
        'generated_notified_at',
        'overdue_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'due_date' => 'date',
            'generated_at' => 'datetime',
            'pdf_generated_at' => 'datetime',
            'plan_snapshot_at' => 'datetime',
            'generated_notified_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'base_amount' => 'decimal:2',
            'base_amount_taxable' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'plan_price_snapshot' => 'decimal:2',
            'amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (Invoice $invoice) {
            $immutable = [
                'base_amount', 'base_amount_taxable', 'discount_percent', 'discount_amount',
                'tax_amount', 'tax_rate', 'tax_regime', 'amount',
                'period_from', 'period_to', 'due_date', 'company_nit', 'subscription_id',
                'plan_name_snapshot', 'plan_price_snapshot', 'plan_snapshot_at',
                'company_promo_code_id',
            ];

            foreach ($immutable as $field) {
                if ($invoice->isDirty($field)) {
                    throw new \LogicException("Invoice field is immutable: {$field}");
                }
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<InvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'voided_by_invoice_id');
    }

    /** @return BelongsTo<CompanyPromoCode, $this> */
    public function companyPromoCode(): BelongsTo
    {
        return $this->belongsTo(CompanyPromoCode::class);
    }
}
