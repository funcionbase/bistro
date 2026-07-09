<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PdfEmptyDataException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\GetInvoicesRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\BillingPlan;
use App\Models\Invoice;
use App\Services\BillingService;
use App\Services\CsvExportService;
use App\Services\InvoicePdfService;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly InvoicePdfService $pdfService,
        private readonly JwtService $jwtService,
        private readonly CsvExportService $csvExportService,
    ) {}

    public function plans(Request $request): JsonResponse
    {
        $plans = BillingPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get(['id', 'slug', 'name', 'description', 'price', 'currency', 'billing_cycle', 'features', 'sort_order']);

        return response()->json(['data' => $plans]);
    }

    public function invoicesCsv(GetInvoicesRequest $request): StreamedResponse|JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $filters = $request->validated();

        try {
            return $this->csvExportService->exportInvoices($companyNit, $filters);
        } catch (PdfEmptyDataException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function subscription(Request $request): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $subscription = $this->billingService->getActiveSubscription($companyNit);

        $overdueInvoices = Invoice::where('company_nit', $companyNit)
            ->where('status', 'overdue')
            ->get(['amount', 'due_date']);

        $overdueTotal = $overdueInvoices->sum('amount');
        $earliestOverdueDate = $overdueInvoices->sortBy('due_date')->first()?->due_date?->toDateString();

        // Detalle de uso DIAN del período en curso — solo para planes con
        // módulo DIAN (#facturación-dian).
        $dianUsage = null;
        if ($subscription?->plan !== null && in_array('dian', $subscription->plan->features ?? [], true)) {
            $dianUsage = $this->billingService->getCurrentPeriodDianUsage($companyNit, $subscription);
        }

        return response()->json([
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'overdue_total' => $overdueTotal,
            'earliest_overdue_date' => $earliestOverdueDate,
            'dian_usage' => $dianUsage,
        ]);
    }

    public function invoices(GetInvoicesRequest $request): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $filters = $request->validated();
        $page = (int) ($filters['page'] ?? 1);

        $paginator = $this->billingService->getInvoiceHistory($companyNit, $page, $filters);

        return response()->json([
            'data' => InvoiceResource::collection($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $invoice = Invoice::with(['subscription.plan', 'lines', 'payments'])
            ->where('id', $id)
            ->where('company_nit', $companyNit)
            ->firstOrFail();

        return response()->json(['invoice' => new InvoiceResource($invoice)]);
    }

    public function download(Request $request, string $id): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $invoice = Invoice::where('id', $id)
            ->where('company_nit', $companyNit)
            ->firstOrFail();

        $this->pdfService->generateAndStore($invoice);

        $signedUrl = URL::temporarySignedRoute(
            'api.billing.invoices.pdf',
            config('billing.download_ttl', 3600),
            ['id' => $id, 'token' => $request->bearerToken()]
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

        $token = $request->query('token');
        if (! $token) {
            abort(403, 'Token de acceso requerido.');
        }

        try {
            $payload = $this->jwtService->verify($token);
        } catch (RuntimeException) {
            abort(403, 'Token de acceso inválido o expirado.');
        }

        $companyNit = $payload['active_company_nit'] ?? null;

        $invoice = Invoice::where('id', $id)
            ->when($companyNit, fn ($q) => $q->where('company_nit', $companyNit))
            ->firstOrFail();

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
}
