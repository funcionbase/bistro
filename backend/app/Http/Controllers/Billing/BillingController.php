<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\GetInvoicesRequest;
use App\Models\Invoice;
use App\Services\BillingService;
use App\Services\FeaturePermissionService;
use App\Services\InvoicePdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expone la suscripción activa, el historial de facturas y descarga de PDFs de factura.
 *
 * subscription(): retorna suscripción + total adeudado + fecha de vencimiento más temprana pendiente.
 * downloadInvoice(): genera el PDF si no existe y retorna una URL firmada de corta duración.
 * servePdf(): sirve el contenido del PDF desde storage; solo acepta URLs firmadas válidas.
 * exportCsv(): genera y descarga el historial de facturas en CSV con BOM UTF-8.
 *
 * @env BILLING_CURRENCY — moneda por defecto (config billing.currency)
 * @env BILLING_GRACE_MONTHS — meses de gracia antes de suspender (config billing.grace_months)
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly InvoicePdfService $pdfService,
        private readonly FeaturePermissionService $permissionService,
    ) {}

    public function subscription(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'company', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $subscription = $this->billingService->getActiveSubscription($companyNit);

        $overdueInvoices = Invoice::where('company_nit', $companyNit)
            ->where('status', 'overdue')
            ->get(['amount', 'due_date']);

        $overdueTotal = $overdueInvoices->sum('amount');
        $earliestOverdueDate = $overdueInvoices->sortBy('due_date')->first()?->due_date?->toDateString();

        return response()->json([
            'subscription' => $subscription,
            'overdue_total' => $overdueTotal,
            'earliest_overdue_date' => $earliestOverdueDate,
        ]);
    }

    public function invoices(GetInvoicesRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'company', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $filters = $request->validated();
        $page = (int) ($filters['page'] ?? 1);

        $paginator = $this->billingService->getInvoiceHistory($companyNit, $page, $filters);

        return response()->json($paginator);
    }

    public function showInvoice(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'company', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $invoice = Invoice::with(['lines', 'payments'])
            ->where('id', $id)
            ->where('company_nit', $companyNit)
            ->firstOrFail();

        return response()->json(['invoice' => $invoice]);
    }

    public function downloadInvoice(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'company', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $invoice = Invoice::where('id', $id)
            ->where('company_nit', $companyNit)
            ->firstOrFail();

        $this->pdfService->generateAndStore($invoice);

        $signedUrl = URL::temporarySignedRoute(
            'api.billing.invoices.pdf',
            config('billing.download_ttl', 3600),
            ['id' => $id]
        );

        return response()->json([
            'url' => $signedUrl,
            'expires_at' => now()->addSeconds(config('billing.download_ttl', 3600))->toIso8601String(),
        ]);
    }

    public function servePdf(Request $request, string $id): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'URL inválida o expirada.');
        }

        $invoice = Invoice::findOrFail($id);

        if (! $invoice->pdf_path || ! Storage::disk(config('billing.storage_disk'))->exists($invoice->pdf_path)) {
            $this->pdfService->generateAndStore($invoice);
            $invoice->refresh();
        }

        $contents = Storage::disk(config('billing.storage_disk'))->get($invoice->pdf_path);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"factura-{$id}.pdf\"",
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->permissionService->assertPermission($request, 'company', 'update');

        $companyNit = $request->attributes->get('active_company_nit');

        $invoices = Invoice::with(['subscription.plan', 'payments'])
            ->where('company_nit', $companyNit)
            ->where('status', '!=', 'voided')
            ->orderBy('period_from', 'desc')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="facturas-'.now()->format('Y-m-d').'.csv"',
        ];

        return response()->streamDownload(function () use ($invoices) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

            fputcsv($handle, [
                'ID', 'Tipo', 'Plan', 'Período Desde', 'Período Hasta',
                'Días', 'Precio Base', 'Descuento %', 'Descuento $',
                'Total', 'Moneda', 'Vencimiento', 'Estado', 'Fecha Pago', 'Referencia Pago',
            ]);

            foreach ($invoices as $invoice) {
                $payment = $invoice->payments->first();
                fputcsv($handle, [
                    $invoice->id,
                    $invoice->type === 'monthly' ? 'Mensual' : 'Prorrateo',
                    $invoice->subscription?->plan?->name ?? '',
                    $invoice->period_from->format('Y-m-d'),
                    $invoice->period_to->format('Y-m-d'),
                    $invoice->days_billed,
                    $invoice->base_amount,
                    $invoice->discount_percent ?? '',
                    $invoice->discount_amount ?? '',
                    $invoice->amount,
                    $invoice->currency,
                    $invoice->due_date->format('Y-m-d'),
                    $invoice->status,
                    $payment?->payment_date?->format('Y-m-d') ?? '',
                    $payment?->payment_reference ?? '',
                ]);
            }

            fclose($handle);
        }, 'facturas-'.now()->format('Y-m-d').'.csv', $headers);
    }
}
