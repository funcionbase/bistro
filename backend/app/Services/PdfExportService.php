<?php

namespace App\Services;

use App\Exceptions\PdfEmptyDataException;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentReceipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Genera PDFs de exportación de pedidos, métricas, domicilios, cupones y facturas usando DomPDF.
 *
 * Límite de registros: config pdf.max_rows (default: 500). Cuando se supera, se aplica el límite
 * y se incluye una advertencia en el PDF (limitApplied=true).
 * Si no hay datos para exportar, lanza PdfEmptyDataException.
 * El logo de la empresa se incrusta en base64 en el PDF si pdf.include_company_logo=true.
 * Retorna una Response HTTP con stream del PDF (Content-Disposition: inline).
 *
 * @env PDF_DRIVER — motor PDF (config pdf.paper_size, pdf.orientation)
 * @env FILESYSTEM_DISK — disco para leer el logo de la empresa
 */
class PdfExportService
{
    /**
     * Exporta la lista de pedidos de una empresa como PDF.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportOrders(string $companyNit, array $filters): Response
    {
        $maxRows = config('pdf.max_rows', 500);

        $query = Order::where('company_nit', $companyNit)
            ->with(['receipts' => function ($q) {
                $q->orderByDesc('created_at');
            }])
            ->orderByDesc('ordered_at');

        if (! empty($filters['date_from'])) {
            $query->where('ordered_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('ordered_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $query->whereIn('status', $statuses);
        }

        $total = $query->count();

        if ($total === 0) {
            throw new PdfEmptyDataException;
        }

        $limitApplied = $total > $maxRows;
        $orders = $query->limit($maxRows)->get();

        // Agregaciones contables por método (gross/refunds/net) calculadas en SQL
        // desde payment_receipts.amount. amount es signed: cobros positivos,
        // refunds negativos. Net por método = SUM(amount).
        $orderIds = $orders->pluck('id')->all();

        $byMethod = [];
        if (! empty($orderIds)) {
            $rows = PaymentReceipt::whereIn('order_id', $orderIds)
                ->whereNotNull('payment_method')
                ->selectRaw('payment_method, SUM(CASE WHEN amount >= 0 THEN amount ELSE 0 END) AS gross, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) AS refunds, SUM(amount) AS net, COUNT(*) AS receipts_count')
                ->groupBy('payment_method')
                ->get();

            foreach ($rows as $row) {
                $byMethod[$row->payment_method] = [
                    'gross' => (float) $row->gross,
                    'refunds' => (float) $row->refunds,
                    'net' => (float) $row->net,
                    'count' => (int) $row->receipts_count,
                ];
            }
        }

        // Asegurar que los 4 métodos comunes aparezcan aunque sean 0.
        foreach (['cash', 'card', 'transfer', 'refund'] as $m) {
            $byMethod[$m] = $byMethod[$m] ?? ['gross' => 0.0, 'refunds' => 0.0, 'net' => 0.0, 'count' => 0];
        }

        // Gross = suma de cobros (filas cash/card/transfer; el row 'refund' tiene gross=0).
        // Refunds = SUM de las devoluciones (filas con amount<0 → almacenadas en 'refund').
        // Net = Gross - Refunds.
        $grossTotal = (float) array_sum(array_map(fn ($r) => $r['gross'], $byMethod));
        $refundsTotal = (float) array_sum(array_map(fn ($r) => $r['refunds'], $byMethod));
        $netTotal = $grossTotal - $refundsTotal;

        // Totales tributarios sobre las órdenes mostradas (snapshot al momento
        // de cada orden — refleja el régimen vigente al cobrar).
        $taxableSubtotal = (float) $orders->sum(fn (Order $o) => (float) $o->subtotal);
        $taxTotal = (float) $orders->sum(fn (Order $o) => (float) $o->tax_amount);
        // Propinas (CO): separadas del revenue y de la base gravable. Solo informativo.
        $tipsTotal = (float) $orders->sum(fn (Order $o) => (float) $o->tip_amount);

        $branding = $this->buildCompanyBranding($companyNit);

        $data = [
            'orders' => $orders,
            'filters' => $filters,
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
            'totalRecords' => $total,
            'limitApplied' => $limitApplied,
            'maxRows' => $maxRows,
            'footerText' => config('pdf.footer_text', 'Generado por bistro'),
            'byMethod' => $byMethod,
            'grossTotal' => $grossTotal,
            'refundsTotal' => $refundsTotal,
            'netTotal' => $netTotal,
            'taxableSubtotal' => $taxableSubtotal,
            'taxTotal' => $taxTotal,
            'tipsTotal' => $tipsTotal,
            ...$branding,
        ];

        return $this->streamPdf('pdf.orders', $data, "pedidos_{$this->today()}.pdf");
    }

    /**
     * Devuelve el método de pago original de una orden ('cash'|'card'|'transfer'|'unknown').
     * Lee desde la columna estructurada `payment_method` del receipt más reciente
     * que NO sea de tipo refund. Lo usan los templates Blade para mostrar el método.
     */
    /**
     * Exporta el cierre de caja del período como PDF.
     *
     * @param  array{from: Carbon, to: Carbon, timezone: string}  $period
     * @param  array<string, mixed>  $summary
     */
    public function exportCashDrawer(string $companyNit, array $period, array $summary): Response
    {
        $branding = $this->buildCompanyBranding($companyNit);

        $data = [
            'period' => $period,
            'summary' => $summary,
            'generatedAt' => Carbon::now($period['timezone'] ?? 'America/Bogota')->format('d/m/Y H:i'),
            'footerText' => config('pdf.footer_text', 'Generado por bistro'),
            ...$branding,
        ];

        $filename = sprintf(
            'cierre_caja_%s_a_%s.pdf',
            $period['from']->format('Y-m-d'),
            $period['to']->format('Y-m-d'),
        );

        return $this->streamPdf('pdf.cash-drawer', $data, $filename);
    }

    private function resolvePaymentMethod(Order $order): string
    {
        $receipt = $order->receipts->first(function ($r) {
            return $r->payment_method !== null && $r->payment_method !== 'refund';
        });

        return in_array($receipt?->payment_method, ['cash', 'card', 'transfer'], true)
            ? $receipt->payment_method
            : 'unknown';
    }

    /**
     * Exporta el resumen de métricas operativas como PDF.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportMetrics(string $companyNit, array $filters): Response
    {
        $maxRows = config('pdf.max_rows', 500);

        $query = Order::where('company_nit', $companyNit)
            ->orderByDesc('ordered_at');

        if (! empty($filters['date_from'])) {
            $query->where('ordered_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('ordered_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        $total = $query->count();

        if ($total === 0) {
            throw new PdfEmptyDataException;
        }

        $limitApplied = $total > $maxRows;
        $orders = $query->limit($maxRows)->get();

        // Refunds aplicados en el período: SUM(-amount) en payment_receipts cuyo
        // order pertenece al rango de fechas. Permite mostrar net = gross - refunds.
        $orderIdsInPeriod = $query->clone()->select('id')->pluck('id');
        $totalRefunded = $orderIdsInPeriod->isEmpty() ? 0.0 : (float) PaymentReceipt::whereIn('order_id', $orderIdsInPeriod)
            ->where('payment_method', 'refund')
            ->sum(DB::raw('-amount'));

        $totalTips = (float) $query->clone()->sum('tip_amount');

        $grossRevenue = (float) $query->clone()->whereIn('status', config('orders.revenue'))->sum('total');
        $completedCount = $query->clone()->whereIn('status', config('orders.revenue'))->count();

        /** @var array<string, mixed> $kpis */
        $kpis = [
            'total_orders' => $total,
            'completed' => $completedCount,
            'cancelled' => $query->clone()->where('status', 'cancelled')->count(),
            'refunded' => $query->clone()->where('status', 'refunded')->count(),
            'abandoned' => $query->clone()->where('status', 'abandoned')->count(),
            'total_revenue' => $grossRevenue,
            'total_refunded' => $totalRefunded,
            'net_revenue' => round($grossRevenue - $totalRefunded, 2),
            'total_tips' => $totalTips,
            'average_ticket' => $completedCount > 0 ? round($grossRevenue / $completedCount, 2) : 0.0,
        ];

        $topItems = [];
        foreach ($orders as $order) {
            foreach ($order->items ?? [] as $item) {
                $name = $item['name'] ?? 'Desconocido';
                if (! isset($topItems[$name])) {
                    $topItems[$name] = ['name' => $name, 'qty' => 0, 'revenue' => 0.0];
                }
                $topItems[$name]['qty'] += $item['qty'] ?? 1;
                $topItems[$name]['revenue'] += ($item['qty'] ?? 1) * ($item['price'] ?? 0);
            }
        }

        usort($topItems, fn ($a, $b) => $b['qty'] <=> $a['qty']);
        $topItems = array_slice($topItems, 0, 10);

        $branding = $this->buildCompanyBranding($companyNit);

        $data = [
            'kpis' => $kpis,
            'topItems' => $topItems,
            'filters' => $filters,
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
            'totalRecords' => $total,
            'limitApplied' => $limitApplied,
            'maxRows' => $maxRows,
            'footerText' => config('pdf.footer_text', 'Generado por bistro'),
            ...$branding,
        ];

        return $this->streamPdf('pdf.metrics', $data, "metricas_{$this->today()}.pdf");
    }

    /**
     * Exporta el historial de repartidores como PDF.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportCouriers(string $companyNit, array $filters): Response
    {
        $maxRows = config('pdf.max_rows', 500);

        $query = Delivery::forCompany($companyNit)
            ->with(['deliverer', 'order'])
            ->orderByDesc('assigned_at');

        if (! empty($filters['date_from'])) {
            $query->where('assigned_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('assigned_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $query->whereIn('status', $statuses);
        }

        $total = $query->count();

        if ($total === 0) {
            throw new PdfEmptyDataException;
        }

        $limitApplied = $total > $maxRows;
        $deliveries = $query->limit($maxRows)->get();

        $branding = $this->buildCompanyBranding($companyNit);

        $data = [
            'deliveries' => $deliveries,
            'filters' => $filters,
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
            'totalRecords' => $total,
            'limitApplied' => $limitApplied,
            'maxRows' => $maxRows,
            'footerText' => config('pdf.footer_text', 'Generado por bistro'),
            ...$branding,
        ];

        return $this->streamPdf('pdf.couriers', $data, "repartidores_{$this->today()}.pdf");
    }

    /**
     * Exporta el historial de cupones como PDF.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportCoupons(string $companyNit, array $filters): Response
    {
        $maxRows = config('pdf.max_rows', 500);

        $query = Coupon::where('company_nit', $companyNit)
            ->withCount('redemptions')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            // Escape LIKE wildcards (`%`, `_`) y el caracter de escape `!` para
            // evitar abuso de wildcards (defensa adicional al binding).
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search']);
            $query->whereRaw("code LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }

        $total = $query->count();

        if ($total === 0) {
            throw new PdfEmptyDataException;
        }

        $limitApplied = $total > $maxRows;
        $coupons = $query->limit($maxRows)->get();

        $branding = $this->buildCompanyBranding($companyNit);

        $data = [
            'coupons' => $coupons,
            'filters' => $filters,
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
            'totalRecords' => $total,
            'limitApplied' => $limitApplied,
            'maxRows' => $maxRows,
            'footerText' => config('pdf.footer_text', 'Generado por bistro'),
            ...$branding,
        ];

        return $this->streamPdf('pdf.coupons', $data, "cupones_{$this->today()}.pdf");
    }

    /**
     * Exporta el historial de facturas de una empresa como PDF.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportInvoices(string $companyNit, array $filters): Response
    {
        $query = Invoice::with(['subscription.plan'])
            ->where('company_nit', $companyNit)
            ->where('status', '!=', 'voided')
            ->orderByDesc('period_from');

        if (! empty($filters['date_from'])) {
            $query->where('period_from', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('period_to', '<=', $filters['date_to']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $total = $query->count();

        if ($total === 0) {
            throw new PdfEmptyDataException;
        }

        $invoices = $query->get();
        $totalAmount = $invoices->sum('amount');

        $branding = $this->buildCompanyBranding($companyNit);

        $data = [
            'invoices' => $invoices,
            'totalAmount' => $totalAmount,
            'filters' => $filters,
            'generatedAt' => Carbon::now()->format('d/m/Y H:i'),
            'totalRecords' => $total,
            'limitApplied' => false,
            'maxRows' => $total,
            'footerText' => config('pdf.footer_text', 'Generado por bistro'),
            ...$branding,
        ];

        return $this->streamPdf('pdf.invoices', $data, "facturas_{$this->today()}.pdf");
    }

    /**
     * Construye el branding de la empresa (logo, nombre) para los templates PDF.
     *
     * @return array{logoBase64: string|null, companyName: string}
     */
    private function buildCompanyBranding(string $companyNit): array
    {
        $company = Company::where('nit', $companyNit)->first();

        $companyName = $company?->commercial_name ?? $companyNit;

        $logoBase64 = null;

        if (config('pdf.include_company_logo', true) && $company?->logo_path && Storage::exists($company->logo_path)) {
            $logoContent = Storage::get($company->logo_path);

            if ($logoContent !== null) {
                $mime = Storage::mimeType($company->logo_path) ?: 'image/png';
                $logoBase64 = "data:{$mime};base64,".base64_encode($logoContent);
            }
        }

        return [
            'logoBase64' => $logoBase64,
            'companyName' => $companyName,
        ];
    }

    /**
     * Renderiza una vista Blade como PDF y retorna una respuesta HTTP.
     *
     * @param  array<string, mixed>  $data
     */
    private function streamPdf(string $view, array $data, string $filename): Response
    {
        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper(config('pdf.paper_size', 'A4'), config('pdf.orientation', 'portrait'));

        return $pdf->stream($filename);
    }

    private function today(): string
    {
        return Carbon::today()->format('Y-m-d');
    }
}
