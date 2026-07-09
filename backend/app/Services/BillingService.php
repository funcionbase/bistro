<?php

namespace App\Services;

use App\Jobs\EmitDianInvoiceJob;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\CompanyPromoCode;
use App\Models\DianResolution;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PromoCode;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\CompanyBlockingSoonNotification;
use App\Notifications\CompanyEnteredPastDueNotification;
use App\Notifications\CompanyPaymentBlockedNotification;
use App\Notifications\CompanyReactivatedNotification;
use App\Notifications\CompanyRegistrationApprovedNotification;
use App\Notifications\InvoiceGeneratedNotification;
use App\Notifications\InvoiceOverdueNotification;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gestiona el ciclo de vida de la facturación mensual de suscripciones.
 *
 * Responsabilidades: generación mensual, marcado de past_due, expiración de descuentos y consultas de historial.
 * Idempotencia: generateMonthlyInvoices() salta facturas existentes no-void para el mismo período.
 *
 * Past-due (#175): la transición `companies.status` por atraso de pago la
 * resuelve un único método derivado e idempotente — `recalculateCompanyStatus()`.
 * Es la **única** función que muta el status por motivos de facturación.
 * `markOverdueInvoices` pasa a ser un wrapper: marca invoices `pending → overdue`
 * y delega el recálculo de status. Correr el cron N veces el mismo día deja
 * todo igual.
 *
 * @env BILLING_CURRENCY — moneda de facturación (config billing.currency, default: COP)
 * @env BILLING_PAST_DUE_GRACE_MONTHS — meses de gracia antes de suspended (default 3)
 * @env BILLING_TRIAL_DAYS — días de prueba post-creación (default 90)
 * @env BILLING_DUE_DAY — día de vencimiento de facturas (config billing.due_day)
 */
class BillingService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly NotificationDispatchTracker $dispatchTracker,
    ) {}

    /**
     * Generate monthly invoices for all active subscriptions — post-pago mes vencido.
     *
     * `$forMonth` representa el **mes a facturar**. Si pasas mayo, factura mayo
     * (con due_date en junio). El scheduler corre el día 1 de cada mes y pasa
     * el mes anterior — patrón post-pago (#246 decisión #7).
     *
     * Cambios #246:
     *  - `plan_price_snapshot` se usa en lugar de `plan->price` (drift inmune).
     *  - Desglose IVA: `base_amount_taxable + tax_amount = amount` cuando el
     *    plan tiene `price_includes_tax=true`. Descuento se aplica al bruto
     *    (UBL `AllowanceCharge`).
     *  - Snapshot del plan en el invoice (`plan_name_snapshot`, etc.).
     *  - FK a `company_promo_code_id` cuando hay descuento aplicado.
     *  - Redondeo bancario con `Money::round`.
     *
     * @return list<int> Invoice IDs created
     */
    public function generateMonthlyInvoices(CarbonInterface $forMonth): array
    {
        $periodFrom = $forMonth->copy()->startOfMonth()->toDateString();
        $periodTo = $forMonth->copy()->endOfMonth()->toDateString();
        // due_date = día N del mes posterior al período (post-pago).
        $dueDate = $forMonth->copy()
            ->addMonthNoOverflow()
            ->day(config('billing.due_day', 15))
            ->toDateString();

        $subscriptions = Subscription::with(['plan', 'company'])
            ->where('status', 'active')
            ->whereHas('company', fn ($q) => $q->whereIn('status', ['active', 'past_due']))
            ->get();

        $created = [];
        $periodFromDate = Carbon::parse($periodFrom);

        foreach ($subscriptions as $subscription) {
            // Trial extendido (#193). Si la empresa tiene
            // `paid_billing_starts_at` y el período a facturar arranca antes
            // de esa fecha, NO se genera factura — la empresa sigue en
            // gratuidad. La condición `>=` significa que el período cuyo
            // primer día sea EXACTAMENTE `paid_billing_starts_at` SÍ se
            // factura (primer cobro post-trial).
            $paidStartsAt = $subscription->company?->paid_billing_starts_at;
            if ($paidStartsAt !== null && $paidStartsAt->startOfDay()->gt($periodFromDate)) {
                continue;
            }

            $exists = Invoice::where('subscription_id', $subscription->id)
                ->where('period_from', $periodFrom)
                ->where('period_to', $periodTo)
                ->where('status', '!=', 'voided')
                ->exists();

            if ($exists) {
                continue;
            }

            // Snapshot del plan: precio bruto (incluye IVA si `price_includes_tax`).
            $planSnapshotPrice = (float) ($subscription->plan_price_snapshot ?? $subscription->plan->price);

            // Plan gratuito (Plan Básico $0, 2026-07): nada que facturar — sin
            // esto se emitirían invoices de $0 con correo y emisión DIAN.
            if ($planSnapshotPrice <= 0.0) {
                continue;
            }
            $planName = (string) ($subscription->plan_name_snapshot ?? $subscription->plan->name);
            $taxRate = (float) ($subscription->plan_tax_rate_snapshot ?? $subscription->plan->tax_rate ?? 19.00);
            $taxRegime = (string) ($subscription->plan_tax_regime_snapshot ?? $subscription->plan->tax_regime ?? 'iva_19');
            $priceIncludesTax = (bool) ($subscription->plan->price_includes_tax ?? true);

            // Resolver promo activo para el período.
            $activePromo = $this->resolveActivePromo($subscription->company_nit, $forMonth);
            $discountPct = $activePromo?->discount_percent ?? 0;

            // Cargo por uso DIAN (#facturación-dian): $N COP IVA incluido por
            // documento electrónico emitido en el período. NO recibe descuento
            // — el promo solo aplica a la mensualidad (decisión de producto).
            $dianCounts = $this->countDianDocumentsByResolution(
                $subscription->company_nit, $periodFromDate, Carbon::parse($periodTo),
            );
            $totalDianDocs = (int) $dianCounts->sum('count');
            $dianUnitPrice = (float) config('billing.dian_unit_price', 10);
            $usageBruto = Money::round($totalDianDocs * $dianUnitPrice);

            $breakdown = $this->computeInvoiceBreakdown(
                $planSnapshotPrice, $discountPct, $taxRate, $priceIncludesTax, $usageBruto,
            );

            $daysInMonth = $forMonth->daysInMonth;

            $invoice = DB::transaction(function () use (
                $subscription, $periodFrom, $periodTo, $daysInMonth,
                $planSnapshotPrice, $planName, $taxRate, $taxRegime,
                $breakdown, $dueDate, $activePromo,
                $dianCounts, $totalDianDocs, $dianUnitPrice, $usageBruto,
            ) {
                $inv = Invoice::create([
                    'company_nit' => $subscription->company_nit,
                    'subscription_id' => $subscription->id,
                    'plan_name_snapshot' => $planName,
                    'plan_price_snapshot' => $planSnapshotPrice,
                    'plan_snapshot_at' => $subscription->plan_snapshot_at ?? $subscription->starts_at,
                    'company_promo_code_id' => $activePromo?->id,
                    'type' => 'monthly',
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'days_billed' => $daysInMonth,
                    // Bruto TOTAL pre-descuento (plan + uso DIAN), no solo el
                    // plan: PDF/CSV/frontend muestran "Precio base − Descuento
                    // = Total" y el descuento solo pega sobre el plan, así que
                    // base_amount debe incluir el uso para que esa resta
                    // reconcilie con `amount` (base_amount - discount_amount = amount).
                    'base_amount' => Money::round($planSnapshotPrice + $usageBruto),
                    'base_amount_taxable' => $breakdown['base_amount_taxable'],
                    'discount_percent' => $breakdown['discount_percent'],
                    'discount_amount' => $breakdown['discount_amount'],
                    'tax_amount' => $breakdown['tax_amount'],
                    'tax_rate' => $taxRate,
                    'tax_regime' => $taxRegime,
                    'amount' => $breakdown['amount_final'],
                    'currency' => config('billing.currency', 'COP'),
                    'due_date' => $dueDate,
                    'status' => 'pending',
                ]);

                InvoiceLine::create([
                    'invoice_id' => $inv->id,
                    'description' => "Suscripción {$planName} — ".Carbon::parse($periodFrom)->isoFormat('MMMM YYYY'),
                    'quantity' => 1,
                    'unit_price' => $planSnapshotPrice,
                    'subtotal' => Money::round($breakdown['amount_final'] - $usageBruto),
                    'sort_order' => 1,
                ]);

                // Cargo por uso DIAN: una línea por resolución con documentos
                // emitidos en el período. Sin línea si no hubo documentos.
                if ($totalDianDocs > 0) {
                    $resolutions = DianResolution::query()
                        ->whereIn('id', $dianCounts->pluck('dian_resolution_id'))
                        ->get()
                        ->keyBy('id');

                    $sortOrder = 2;
                    foreach ($dianCounts as $row) {
                        $resolution = $resolutions->get($row->dian_resolution_id);
                        $label = $resolution
                            ? "Res. {$resolution->prefix} ({$resolution->resolution_number})"
                            : 'Resolución DIAN';

                        InvoiceLine::create([
                            'invoice_id' => $inv->id,
                            'description' => "Facturación electrónica DIAN — {$label}: {$row->count} documentos",
                            'quantity' => $row->count,
                            'unit_price' => $dianUnitPrice,
                            'subtotal' => Money::round($row->count * $dianUnitPrice),
                            'sort_order' => $sortOrder++,
                        ]);
                    }
                }

                // Notify dentro del transaction garantiza que el correo solo
                // se dispare si el INSERT de invoice committa (vía afterCommit).
                // Si dos workers compiten por la misma (subscription, period),
                // el UNIQUE constraint deja a uno ganar; sólo el ganador envía.
                if (config('billing.notify_on_generate') && $subscription->company !== null) {
                    $this->notifyOnce(
                        $inv,
                        'generated_notified_at',
                        $subscription->company,
                        new InvoiceGeneratedNotification($inv),
                    );
                }

                return $inv;
            });

            $created[] = $invoice->id;

            // #246 PR-2.5: dispara emisión DIAN del invoice. afterCommit asegura
            // que solo se encole si el invoice persistió. El job es idempotente
            // y N-instance safe (ShouldBeUnique).
            if (config('billing.emit_dian_for_invoices', true)) {
                DB::afterCommit(fn () => EmitDianInvoiceJob::dispatch($invoice->id));
            }
        }

        return $created;
    }

    /**
     * Calcula el desglose contable de un invoice según política #246, extendida
     * con el cargo por uso DIAN (#facturación-dian): el descuento por promo
     * code aplica SOLO a la mensualidad; el cargo por uso ($10/documento) se
     * suma bruto (IVA incluido) después del descuento y el IVA final se
     * extrae sobre el total combinado.
     *
     * Flujo (plan bruto = 300.000, descuento 20%, uso = 500 docs × $10 = 5.000, IVA 19%):
     *   plan_neto          = 300.000 − round(300.000 × 0,20) = 240.000
     *   amount_final       = 240.000 + 5.000 = 245.000
     *   base_amount_taxable = 245.000 / 1,19 = 205.882,35
     *   tax_amount         = 245.000 − 205.882,35 = 39.117,65
     *   total              = 205.882,35 + 39.117,65 = 245.000,00 ✓
     *
     * Con `usageBruto = 0.0` (default) el resultado es idéntico al cálculo
     * previo a #facturación-dian (compatibilidad con planes sin módulo DIAN).
     *
     * @return array{discount_percent: int|null, discount_amount: float|null, amount_final: float, base_amount_taxable: float, tax_amount: float}
     */
    private function computeInvoiceBreakdown(
        float $planPriceBruto,
        int $discountPct,
        float $taxRate,
        bool $priceIncludesTax,
        float $usageBruto = 0.0,
    ): array {
        // Descuento SOLO sobre el plan — el cargo por uso nunca se descuenta.
        $discountAmount = $discountPct > 0
            ? Money::applyPercent($planPriceBruto, $discountPct)
            : null;
        $planNeto = $discountAmount !== null
            ? Money::round($planPriceBruto - $discountAmount)
            : Money::round($planPriceBruto);

        $amountFinal = Money::round($planNeto + $usageBruto);

        // Sin IVA (Régimen Simple): el bruto combinado ES la base.
        if (! $priceIncludesTax || $taxRate <= 0.0) {
            return [
                'discount_percent' => $discountPct > 0 ? $discountPct : null,
                'discount_amount' => $discountAmount,
                'amount_final' => $amountFinal,
                'base_amount_taxable' => $amountFinal,
                'tax_amount' => 0.0,
            ];
        }

        // Con IVA (Régimen Común): se extrae una sola vez sobre el combinado.
        $baseAmountTaxable = Money::extractBase($amountFinal, $taxRate);
        $taxAmount = Money::round($amountFinal - $baseAmountTaxable);

        return [
            'discount_percent' => $discountPct > 0 ? $discountPct : null,
            'discount_amount' => $discountAmount,
            'amount_final' => $amountFinal,
            'base_amount_taxable' => $baseAmountTaxable,
            'tax_amount' => $taxAmount,
        ];
    }

    /**
     * Cuenta `electronic_documents` emitidos por una empresa en [from, to]
     * (inclusive, TZ America/Bogota) agrupados por resolución. `issued_at` es
     * timestamptz, así que el rango se acota con inicio/fin de día. Reutilizado
     * por `generateMonthlyInvoices` (período ya cerrado) y por
     * `getCurrentPeriodDianUsage` (período en curso).
     *
     * Cuenta TODOS los `document_type` y TODOS los `status` — cualquier
     * documento que consumió consecutivo (decisión de producto
     * #facturación-dian): el conteo es estable en el tiempo y no cambia si un
     * documento transiciona de estado después del cierre del período.
     *
     * Las invoices SaaS de flexyflow generan `electronic_documents` con
     * `company_nit = FLEXYFLOW_NIT`, no el de la empresa cliente — el filtro
     * por `$companyNit` ya las excluye sin lógica adicional.
     *
     * @return Collection<int, object{dian_resolution_id: string, count: int}>
     */
    public function countDianDocumentsByResolution(string $companyNit, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return ElectronicDocument::query()
            ->where('company_nit', $companyNit)
            ->whereBetween('issued_at', [
                $from->copy()->startOfDay(),
                $to->copy()->endOfDay(),
            ])
            ->selectRaw('dian_resolution_id, COUNT(*) as count')
            ->groupBy('dian_resolution_id')
            ->get();
    }

    /**
     * Uso DIAN del período EN CURSO (mes calendario actual) para el panel de
     * `company/settings` — estimado, no aplica descuento de promo (el
     * descuento real solo se ve reflejado en el invoice ya generado).
     *
     * @return array{period_from: string, period_to: string, unit_price: float, total_documents: int, usage_amount: float, plan_amount: float, estimated_total: float, resolutions: list<array{resolution_id: string, prefix: ?string, resolution_number: ?string, document_type: ?string, count: int}>}
     */
    public function getCurrentPeriodDianUsage(string $companyNit, ?Subscription $subscription = null): array
    {
        $now = CarbonImmutable::now('America/Bogota');
        $periodFrom = $now->startOfMonth();
        $periodTo = $now->endOfMonth();

        $counts = $this->countDianDocumentsByResolution($companyNit, $periodFrom, $periodTo);
        $unitPrice = (float) config('billing.dian_unit_price', 10);
        $totalDocs = (int) $counts->sum('count');
        $usageAmount = Money::round($totalDocs * $unitPrice);

        $resolutions = DianResolution::query()
            ->whereIn('id', $counts->pluck('dian_resolution_id'))
            ->get()
            ->keyBy('id');

        $breakdown = $counts->map(fn ($row) => [
            'resolution_id' => $row->dian_resolution_id,
            'prefix' => $resolutions->get($row->dian_resolution_id)?->prefix,
            'resolution_number' => $resolutions->get($row->dian_resolution_id)?->resolution_number,
            'document_type' => $resolutions->get($row->dian_resolution_id)?->document_type,
            'count' => (int) $row->count,
        ])->values()->all();

        $planAmount = (float) (($subscription ?? $this->getActiveSubscription($companyNit))?->plan_price_snapshot ?? 0);

        return [
            'period_from' => $periodFrom->toDateString(),
            'period_to' => $periodTo->toDateString(),
            'unit_price' => $unitPrice,
            'total_documents' => $totalDocs,
            'usage_amount' => $usageAmount,
            'plan_amount' => $planAmount,
            'estimated_total' => Money::round($planAmount + $usageAmount),
            'resolutions' => $breakdown,
        ];
    }

    /**
     * Resuelve el `CompanyPromoCode` activo para una empresa al período dado.
     * Retorna null si no hay promo activo aplicable al mes de facturación.
     */
    private function resolveActivePromo(string $companyNit, CarbonInterface $periodFrom): ?CompanyPromoCode
    {
        return CompanyPromoCode::query()
            ->where('company_nit', $companyNit)
            ->activeForDate($periodFrom)
            ->first();
    }

    /**
     * Marca facturas pending → overdue y dispara recálculo de status por empresa.
     *
     * @return list<int> Invoice IDs updated
     */
    public function markOverdueInvoices(CarbonInterface $today): array
    {
        $overdueInvoices = Invoice::with('company')
            ->where('status', 'pending')
            ->where('due_date', '<', $today->toDateString())
            ->get();

        $affected = [];
        $touchedNits = [];

        foreach ($overdueInvoices as $invoice) {
            DB::transaction(function () use ($invoice, &$affected) {
                // lockForUpdate sincroniza concurrencia N-instance: si dos
                // workers procesan la misma invoice al mismo tiempo, el segundo
                // espera al COMMIT del primero, ve status='overdue' y skipea
                // sin disparar audit ni notify duplicado.
                $fresh = Invoice::query()
                    ->where('id', $invoice->id)
                    ->lockForUpdate()
                    ->first();

                if ($fresh === null || $fresh->status !== 'pending') {
                    return;
                }

                // Facturas de $0 (descuento 100% u otro caso borde): se
                // auto-pagan al vencimiento — no tiene sentido exigir el pago
                // de cero pesos ni arrastrar la empresa a past_due.
                if ((float) $fresh->amount <= 0.0) {
                    $fresh->forceFill(['status' => 'paid'])->save();

                    $this->auditService->log(
                        'invoice.auto_paid_zero_amount',
                        null,
                        $fresh,
                        ['company_nit' => $fresh->company_nit, 'due_date' => $fresh->due_date?->toDateString()]
                    );

                    return;
                }

                $fresh->forceFill(['status' => 'overdue'])->save();

                $this->auditService->log(
                    'invoice.overdue',
                    null,
                    $fresh,
                    ['company_nit' => $fresh->company_nit]
                );

                if (config('billing.notify_on_overdue') && $invoice->company !== null) {
                    $this->notifyOnce(
                        $fresh,
                        'overdue_notified_at',
                        $invoice->company,
                        new InvoiceOverdueNotification($fresh),
                    );
                }

                $affected[] = $fresh->id;
            });

            $touchedNits[$invoice->company_nit] = true;
        }

        // Además de las empresas con invoices recién marcadas como overdue,
        // recalcular las empresas que ya están en past_due|suspended para detectar:
        //  - past_due cuya gracia expiró → suspended
        //  - past_due|suspended que liquidó todas las invoices → active
        $companiesToRecalc = Company::query()
            ->whereIn('status', ['active', 'past_due', 'suspended'])
            ->where(function ($q) use ($touchedNits) {
                $q->whereIn('status', ['past_due', 'suspended']);
                if (! empty($touchedNits)) {
                    $q->orWhereIn('nit', array_keys($touchedNits));
                }
            })
            ->get();

        foreach ($companiesToRecalc as $company) {
            $this->recalculateCompanyStatus($company, $today);
        }

        return $affected;
    }

    /**
     * Recalcula `companies.status` derivado de invoices + trial + past_due_started_at.
     *
     * Es la única función que debe mutar `companies.status` por motivos de
     * facturación. Idempotente: correr N veces deja el mismo resultado.
     * Se invoca desde `markOverdueInvoices()` (cron diario) y desde
     * `settleCompanyArrears()` (flujo síncrono al aprobar comprobante).
     *
     * Devuelve el status post-recálculo.
     */
    public function recalculateCompanyStatus(Company $company, CarbonInterface $today): string
    {
        $graceMonths = (int) config('billing.past_due_grace_months', 3);
        $trialDays = (int) config('billing.trial_days', 90);

        return DB::transaction(function () use ($company, $today, $graceMonths, $trialDays) {
            /** @var Company $fresh */
            $fresh = Company::query()
                ->where('nit', $company->nit)
                ->lockForUpdate()
                ->firstOrFail();

            // pending_activation, rejected, inactive: fuera del scope de past_due.
            if (! in_array($fresh->status, ['active', 'past_due', 'suspended'], true)) {
                return $fresh->status;
            }

            $todayDate = $today->copy()->startOfDay();
            $createdAt = $fresh->created_at?->copy()?->startOfDay();

            // Trial efectivo (#193): si la empresa tiene `paid_billing_starts_at`,
            // ese es el límite autoritativo. Si está vacío (legacy), caemos al
            // cálculo histórico de `created_at + BILLING_TRIAL_DAYS`. Mientras
            // el trial efectivo esté en el futuro, la empresa sigue `active`
            // aun si por alguna razón hay invoices históricas (caso edge).
            $effectiveTrialEnd = $fresh->paid_billing_starts_at?->copy()->startOfDay();
            if ($effectiveTrialEnd === null && $createdAt !== null) {
                $effectiveTrialEnd = $createdAt->copy()->addDays($trialDays);
            }
            $trialActive = $effectiveTrialEnd !== null && $effectiveTrialEnd->gt($todayDate);

            $hasOverdue = $fresh->invoices()
                ->whereIn('status', ['overdue', 'pending'])
                ->where('due_date', '<', $todayDate->toDateString())
                ->exists();

            $target = match (true) {
                $trialActive => 'active',
                ! $hasOverdue => 'active',
                $fresh->status === 'active' => 'past_due',
                $fresh->status === 'past_due' => $this->pastDueGraceExpired($fresh, $todayDate, $graceMonths)
                    ? 'suspended'
                    : 'past_due',
                $fresh->status === 'suspended' => 'suspended',
                default => $fresh->status,
            };

            if ($target === $fresh->status) {
                // Aún sin cambio de status: evaluar si toca recordatorio "se
                // bloquea pronto". Idempotencia diaria garantizada por
                // `blocking_soon_notified_on` (DATE): si la columna ya tiene
                // hoy, skipea. Doble run del cron / dos workers EC2
                // concurrentes obtienen 1 solo correo.
                if ($fresh->status === 'past_due' && $fresh->expected_block_at !== null) {
                    $daysLeft = (int) $todayDate->diffInDays($fresh->expected_block_at, false);
                    if ($daysLeft >= 1 && $daysLeft <= 7) {
                        $this->notifyOnceForToday(
                            $fresh,
                            'blocking_soon_notified_on',
                            new CompanyBlockingSoonNotification($fresh, $daysLeft),
                        );
                    }
                }

                return $target;
            }

            $previous = $fresh->status;
            $fresh->status = $target;

            // active → past_due: marcar inicio + cache de bloqueo previsto.
            // Reseteamos `reactivated_notified_at` para permitir reactivación
            // futura, y limpiamos blocking_soon_notified_on por si la empresa
            // viene de un ciclo previo (defensa en profundidad).
            if ($previous === 'active' && $target === 'past_due') {
                $fresh->past_due_started_at = $todayDate;
                $fresh->expected_block_at = $todayDate->copy()->addMonthsNoOverflow($graceMonths);
                $fresh->payment_blocked_at = null;
                $fresh->reactivated_notified_at = null;
                $fresh->blocking_soon_notified_on = null;
                $fresh->suspended_notified_at = null;
            }

            // past_due → suspended: aplicar bloqueo. No reseteamos
            // past_due_notified_at: ya se notificó la entrada a past_due.
            if ($previous === 'past_due' && $target === 'suspended') {
                $fresh->payment_blocked_at = $todayDate;
            }

            // {past_due|suspended} → active: liquidación. Limpiar todos los rastros
            // + reset markers de past_due/suspended/blocking_soon para que un
            // ciclo futuro vuelva a enviar.
            if (in_array($previous, ['past_due', 'suspended'], true) && $target === 'active') {
                $fresh->past_due_started_at = null;
                $fresh->expected_block_at = null;
                $fresh->payment_blocked_at = null;
                $fresh->last_paid_at = $todayDate;
                $fresh->past_due_notified_at = null;
                $fresh->suspended_notified_at = null;
                $fresh->blocking_soon_notified_on = null;
            }

            // Notificación derivada de la transición. Doble defensa:
            //  1. Anti-spam natural: una misma transición sólo ocurre una vez
            //     porque al re-correr el cron el status ya cambió ($previous
            //     ya no matchea).
            //  2. markOnce(): si dos workers EC2 corrieran el recalc en paralelo
            //     sobre la misma fila (lockForUpdate los serializa), el marker
            //     garantiza single-send incluso si las transiciones se
            //     replicaran por bug futuro.
            // Se decide y marca ANTES del save para fusionar el marker de notif
            // con el UPDATE de status + markers de transición (un solo save).
            [$marker, $notification] = match (true) {
                $previous === 'active' && $target === 'past_due' => ['past_due_notified_at', new CompanyEnteredPastDueNotification($fresh)],
                $previous === 'past_due' && $target === 'suspended' => ['suspended_notified_at', new CompanyPaymentBlockedNotification($fresh)],
                in_array($previous, ['past_due', 'suspended'], true) && $target === 'active' => ['reactivated_notified_at', new CompanyReactivatedNotification($fresh)],
                default => [null, null],
            };

            $pendingNotification = ($notification !== null && $marker !== null)
                ? $this->markOnce($fresh, $marker, $notification)
                : null;

            $fresh->save();

            $action = match (true) {
                $previous === 'active' && $target === 'past_due' => 'company.past_due_started',
                $previous === 'past_due' && $target === 'suspended' => 'company.payment_blocked',
                in_array($previous, ['past_due', 'suspended'], true) && $target === 'active' => 'company.past_due_cleared',
                default => 'company.status_recalculated',
            };

            $this->auditService->log($action, null, $fresh, [
                'from' => $previous,
                'to' => $target,
                'past_due_started_at' => $fresh->past_due_started_at?->toIso8601String(),
                'expected_block_at' => $fresh->expected_block_at?->toDateString(),
                'payment_blocked_at' => $fresh->payment_blocked_at?->toIso8601String(),
                'today' => $todayDate->toDateString(),
            ]);

            if ($pendingNotification !== null) {
                DB::afterCommit(fn () => $this->notifyCompanyUsers($fresh, $pendingNotification));
            }

            return $target;
        });
    }

    /**
     * Wrapper síncrono: tras marcar invoices como `paid` desde el flujo de
     * comprobante, recalcula el status de la empresa. Si todo está al día,
     * la empresa vuelve a `active` automáticamente.
     */
    public function settleCompanyArrears(Company $company, CarbonInterface $today): string
    {
        return $this->recalculateCompanyStatus($company, $today);
    }

    /**
     * Expire active company_promo_codes whose ends_at has passed.
     * #246 — reemplaza el legacy `expireDiscounts` (subscription_discounts).
     *
     * Delega a `PromoCodeService::expireOverdue` que audita uno por uno.
     */
    public function expireDiscounts(CarbonInterface $today): int
    {
        /** @var PromoCodeService $promoCodeService */
        $promoCodeService = app(PromoCodeService::class);

        return $promoCodeService->expireOverdue($today);
    }

    public function getActiveSubscription(string $companyNit): ?Subscription
    {
        return Subscription::with('plan')
            ->where('company_nit', $companyNit)
            ->where('status', 'active')
            ->first();
    }

    /**
     * ¿El plan ACTIVO (no el snapshot histórico) de la empresa incluye la
     * feature dada? Lee `billing_plans.features` en vivo — un upgrade de
     * plan habilita la feature de inmediato, sin esperar al próximo ciclo de
     * facturación. Usado para gatear el módulo DIAN (feature `'dian'`,
     * exclusivo del Plan Plus) en `EnsurePlanFeature`, `DianDispatchService`
     * y `BootstrapService`.
     */
    public function companyHasFeature(string $companyNit, string $feature): bool
    {
        $plan = $this->getActiveSubscription($companyNit)?->plan;

        return $plan !== null && in_array($feature, $plan->features ?? [], true);
    }

    /**
     * @param  array{status?: string, year?: int}  $filters
     */
    public function getInvoiceHistory(string $companyNit, int $page = 1, array $filters = []): LengthAwarePaginator
    {
        $query = Invoice::with(['lines', 'payments'])
            ->where('company_nit', $companyNit)
            ->where('status', '!=', 'voided')
            ->orderBy('period_from', 'desc');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['year'])) {
            $query->whereYear('period_from', $filters['year']);
        }

        return $query->paginate(perPage: 15, page: $page);
    }

    /**
     * #257 — Activa una empresa en `pending_activation` y dispara la
     * notificacion de aprobacion con info del plan.
     *
     * Garantias:
     *  - Transaccion + lockForUpdate sobre la empresa (concurrencia segura).
     *  - Asegura una `Subscription` activa para la empresa con snapshot
     *    completo del plan (necesario para BillingPlanPresenter).
     *  - Aplica la prueba gratuita como descuento 100% × N meses (promo cuyo
     *    slug está en `config('billing.trial_promo_code')`, N vive en la fila
     *    del promo). `paid_billing_starts_at` se deriva del fin de ese promo —
     *    durante el trial no se emiten facturas. Si la empresa ya trae un promo
     *    activo (marketing por enrollment), se respeta y el trial se deriva de
     *    él. Sin promo de trial sembrado, cae al fallback legacy `BILLING_TRIAL_DAYS`.
     *  - AuditService::log('company.activated', ...) con metadata reconstructible.
     *  - notifyOnce via marker `activation_notified_at` para idempotencia
     *    cross-instance EC2.
     *
     * Reglas:
     *  - Si la empresa NO esta en `pending_activation`, lanza DomainException.
     *  - Si NO tiene subscription activa y `$planSlug` se pasa, crea la sub.
     *  - Si NO tiene subscription activa y `$planSlug` es null, cae al plan
     *    default.
     *  - Si YA tiene subscription activa y `$planSlug` difiere, lanza
     *    DomainException (cambio de plan es flujo aparte).
     *
     * @param  ?string  $planSlug  Slug del plan; null = default.
     * @param  ?string  $notes  Texto libre auditable (motivo, ticket, etc.).
     * @param  ?User  $approver  Usuario auditor (null = CLI).
     */
    public function activateCompany(
        Company $company,
        ?string $planSlug = null,
        ?string $notes = null,
        ?User $approver = null,
    ): Subscription {
        return DB::transaction(function () use ($company, $planSlug, $notes, $approver): Subscription {
            $fresh = Company::query()->where('id', $company->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'pending_activation') {
                throw new DomainException(
                    "La empresa {$fresh->nit} no esta en pending_activation (estado actual: {$fresh->status}). ".
                    'Estados validos para activacion inicial: pending_activation.'
                );
            }

            $subscription = $this->ensureActiveSubscription($fresh, $planSlug);

            $previousStatus = $fresh->status;

            // Prueba gratuita como descuento 100% × N meses. El fin del promo
            // marca el primer día facturable; antes de eso no se generan
            // facturas (generateMonthlyInvoices saltea por paid_billing_starts_at).
            $trialEndsAt = $this->resolveOrApplyTrialPromo($fresh);

            $updates = ['status' => 'active'];
            if ($fresh->paid_billing_starts_at === null) {
                $updates['paid_billing_starts_at'] = $trialEndsAt !== null
                    ? $trialEndsAt->toDateString()
                    // Fallback legacy: sin promo de trial sembrado, el trial
                    // cae al valor por días (BILLING_TRIAL_DAYS).
                    : now()->addDays((int) config('billing.trial_days', 90))->toDateString();
            }
            $fresh->forceFill($updates)->save();

            $this->auditService->log(
                action: 'company.activated',
                user: $approver,
                auditable: $fresh,
                data: [
                    'previous_status' => $previousStatus,
                    'new_status' => 'active',
                    'subscription_id' => $subscription->id,
                    'billing_plan_id' => $subscription->billing_plan_id,
                    'plan_name_snapshot' => $subscription->plan_name_snapshot,
                    'plan_price_snapshot' => $subscription->plan_price_snapshot,
                    'paid_billing_starts_at' => optional($fresh->paid_billing_starts_at)->toDateString(),
                    // CLI (companies:approve) pasa approver=null; un futuro flujo
                    // web pasaria el User que aprobo. El audit refleja la via real.
                    'approved_via' => $approver !== null ? 'panel' : 'artisan_command',
                    'notes' => $notes,
                ],
            );

            $this->notifyOnce(
                guard: $fresh,
                marker: 'activation_notified_at',
                company: $fresh,
                notification: new CompanyRegistrationApprovedNotification($fresh, $subscription),
            );

            return $subscription;
        });
    }

    /**
     * Garantiza que la empresa tenga una Subscription `active` con snapshot
     * del plan completo. Idempotente.
     *
     *  - Sin subscription + $planSlug: crea la sub con snapshot del plan.
     *  - Sin subscription + null: usa el plan default.
     *  - Con subscription + $planSlug compatible: skip (no crea duplicado).
     *  - Con subscription + $planSlug distinto: lanza DomainException.
     */
    private function ensureActiveSubscription(Company $company, ?string $planSlug): Subscription
    {
        $existing = $company->activeSubscription()->first();

        if ($existing !== null) {
            if ($planSlug !== null) {
                $existing->loadMissing('plan');
                $existingSlug = $existing->plan?->slug;
                if ($existingSlug !== null && $existingSlug !== $planSlug) {
                    throw new DomainException(
                        "La empresa {$company->nit} ya tiene una subscription activa al plan '{$existingSlug}'. ".
                        "No se puede cambiar a '{$planSlug}' desde el comando de aprobacion — usar el flujo de cambio de plan."
                    );
                }
            }

            return $existing;
        }

        $plan = $planSlug !== null
            ? BillingPlan::query()->where('slug', $planSlug)->where('is_active', true)->first()
            : BillingPlan::default();

        if ($plan === null) {
            throw new DomainException(
                $planSlug !== null
                    ? "No existe plan activo con slug '{$planSlug}'."
                    : 'No hay un plan default configurado (BillingPlan::default()).'
            );
        }

        return Subscription::create([
            'company_nit' => $company->nit,
            'billing_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'plan_name_snapshot' => $plan->name,
            'plan_price_snapshot' => $plan->price,
            'plan_features_snapshot' => $plan->features,
            'plan_tax_regime_snapshot' => $plan->tax_regime,
            'plan_tax_rate_snapshot' => $plan->tax_rate,
            'plan_snapshot_at' => now(),
        ]);
    }

    /**
     * Resuelve la prueba gratuita de la empresa y devuelve la fecha en que
     * termina el período gratis (= primer día facturable / paid_billing_starts_at),
     * o null si no aplica trial.
     *
     *  - Si la empresa YA tiene un promo activo (p.ej. marketing aplicado por
     *    `?promo=` en enrollment), NO se sobreescribe: se respeta y se deriva el
     *    fin del trial de su `ends_at`.
     *  - Si no, se aplica el promo de trial (slug en `config('billing.trial_promo_code')`,
     *    100% × N meses con N en la fila del promo) arrancando el primer día del
     *    mes de activación, y se devuelve su `ends_at`. Arrancar a inicio de mes
     *    evita que un alta a mitad de mes empuje el período gratis sobre N meses.
     *  - Si no hay promo activo ni promo de trial sembrado, devuelve null (el
     *    caller cae al trial legacy por días).
     *
     * Nunca rompe la activación: cualquier fallo al aplicar el promo se loggea y
     * devuelve null (la empresa se activa igual, con el fallback legacy). Corre
     * dentro de la transacción + lockForUpdate de activateCompany; applyToCompany
     * usa savepoint anidado, así que un fallo suyo no aborta la activación.
     */
    private function resolveOrApplyTrialPromo(Company $company): ?CarbonImmutable
    {
        $existing = CompanyPromoCode::query()
            ->where('company_nit', $company->nit)
            ->where('status', 'active')
            ->first();

        if ($existing !== null) {
            return CarbonImmutable::instance($existing->ends_at);
        }

        $trialSlug = (string) config('billing.trial_promo_code', 'TRIAL3');

        /** @var PromoCode|null $trialCode */
        $trialCode = PromoCode::query()
            ->where('code', $trialSlug)
            ->where('status', 'active')
            ->first();

        if ($trialCode === null) {
            return null;
        }

        try {
            $application = app(PromoCodeService::class)->applyToCompany(
                $company,
                $trialCode,
                appliedVia: 'github_action',
                startsAt: CarbonImmutable::now('America/Bogota')->startOfMonth(),
            );

            return CarbonImmutable::instance($application->ends_at);
        } catch (Throwable $e) {
            Log::warning('Trial promo skipped on company activation', [
                'company_nit' => $company->nit,
                'code' => $trialCode->code,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function pastDueGraceExpired(Company $company, CarbonInterface $today, int $graceMonths): bool
    {
        if ($company->past_due_started_at === null) {
            // Defensivo: empresa en past_due sin timestamp (caso raro post-deploy).
            // No bloquear hasta que el siguiente run derive el timestamp.
            return false;
        }

        return $today->gte($company->past_due_started_at->copy()->addMonthsNoOverflow($graceMonths));
    }

    /**
     * Calcula el descuento aplicable a una empresa para un período.
     * #246 — ahora lee de `company_promo_codes` (snapshot inmutable).
     *
     * Solo 1 promo activo por empresa (UNIQUE parcial DB), así que el cálculo
     * es directo. Mantiene la firma del método para compatibilidad con
     * llamadores existentes — pero la lógica de generación de invoice ya
     * consume `resolveActivePromo` directamente para acceder al ID FK.
     */
    private function calculateDiscount(string $companyNit, CarbonInterface $periodFrom): float
    {
        $active = $this->resolveActivePromo($companyNit, $periodFrom);

        return $active !== null ? (float) $active->discount_percent : 0.0;
    }

    /**
     * #257 — Encola la notification a owners + admins activos de la empresa
     * con defensa idempotente cross-instance.
     *
     * Doble capa de proteccion:
     *  1. `Company::usersToNotifyForBilling()` resuelve destinatarios (filtro
     *     centralizado: owners + admins activos, deduplicado).
     *  2. `DedupedMailChannel` deduplica cada envio en el momento del send
     *     (dentro del worker) via INSERT a `notification_dispatches` con UNIQUE
     *     compuesto (notification_class, idempotency_key, user_id). Si el INSERT
     *     choca, NO se manda el correo y se loggea
     *     `notification.dispatch_skipped_duplicate`. Como corre en el worker,
     *     cubre reintentos de cola ademas de ejecuciones cron paralelas y
     *     disparos manuales.
     *
     * La notification DEBE implementar BillingNotificationContract
     * (idempotencyKey + dispatchMetadata + companyNit), so pena de
     * InvalidArgumentException — defensa para que ninguna notif billing se
     * cuele sin tracking.
     */
    private function notifyCompanyUsers(Company $company, object $notification): void
    {
        $this->dispatchTracker->dispatchToUsers(
            $company->usersToNotifyForBilling(),
            $notification,
        );
    }

    /**
     * Marca un guard para notificación at-most-once SIN persistir (el caller
     * decide cuándo hacer el `save()`). Retorna la notif si corresponde
     * enviarla, o null si el marker ya estaba seteado (otra instancia o un run
     * previo ya la disparó).
     *
     * Separar "decidir + marcar" de "persistir" permite fusionar el marker en
     * el mismo `save()` del caller (ver recalculateCompanyStatus) en vez de un
     * UPDATE extra.
     *
     * Requiere que el caller tenga lockForUpdate sobre `$guard` dentro de una
     * transacción — sino dos instancias podrían pasar el check en paralelo.
     */
    private function markOnce(Model $guard, string $marker, object $notification): ?object
    {
        if ($guard->{$marker} !== null) {
            return null;
        }

        $guard->{$marker} = now();

        return $notification;
    }

    /**
     * Envía una notification garantizando at-most-once cross-instance EC2.
     *
     * Patrón:
     *   1. El caller toma `lockForUpdate` sobre `$guard` y entra a transaction.
     *   2. `markOnce` decide y marca el guard en memoria (skip si ya seteado).
     *   3. Se persiste el guard (sella el resultado contra concurrencia al
     *      commit) y se programa el send vía `DB::afterCommit()`.
     *   4. El dedup a nivel (notif, user destinatario) y la defensa contra
     *      reintentos de cola la da DedupedMailChannel al enviar en el worker.
     *      Si el SMTP falla, el marker persiste — at-most-once (preferimos
     *      perder un mensaje a disparar copias).
     *
     * Para envíos recurrentes 1×/día usar `notifyOnceForToday`.
     *
     * @param  Model  $guard  Invoice o Company con lockForUpdate aplicado.
     * @param  string  $marker  Nombre de la columna timestamp del guard.
     * @param  Company  $company  Empresa receptora (resuelve los users a notificar).
     * @param  object  $notification  Instancia de Notification a enviar.
     */
    private function notifyOnce(Model $guard, string $marker, Company $company, object $notification): void
    {
        if ($this->markOnce($guard, $marker, $notification) === null) {
            return;
        }

        $guard->save();

        DB::afterCommit(function () use ($company, $notification) {
            $this->notifyCompanyUsers($company, $notification);
        });
    }

    /**
     * Variante para envíos diarios recurrentes (BlockingSoon: cron 1×/día
     * durante la cuenta regresiva). Compara contra DATE en vez de NOT NULL,
     * permitiendo 1 envío por día independientemente de cuántas veces corra
     * el recalculator. Caller debe tener lockForUpdate sobre el Company.
     */
    private function notifyOnceForToday(Company $company, string $marker, object $notification): void
    {
        $today = now()->toDateString();
        $lastNotified = $company->{$marker}?->toDateString();

        if ($lastNotified === $today) {
            return;
        }

        $company->forceFill([$marker => $today])->save();

        DB::afterCommit(function () use ($company, $notification) {
            $this->notifyCompanyUsers($company, $notification);
        });
    }
}
