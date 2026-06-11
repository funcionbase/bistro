<?php

namespace App\Services;

use App\Exceptions\PdfEmptyDataException;
use App\Models\Invoice;
use App\Models\Order;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Genera streams CSV con BOM UTF-8 para compatibilidad con Excel/LibreOffice (Cyrillic/Latin/acentos).
 *
 * Las filas se emiten en streaming usando Order::chunk(); soporta volúmenes > pdf.max_rows
 * (no aplica el límite del PDF). Si no hay datos, lanza PdfEmptyDataException → 422.
 *
 * @env FILESYSTEM_DISK — disco donde se serían los exports en disco (no usado por defecto).
 */
class CsvExportService
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportOrders(string $companyNit, array $filters): StreamedResponse
    {
        $query = Order::where('company_nit', $companyNit)
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

        if ($query->clone()->count() === 0) {
            throw new PdfEmptyDataException;
        }

        $filename = "pedidos_{$this->today()}.csv";

        $response = new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 — necesario para que Excel detecte la codificación correctamente.
            fwrite($handle, self::BOM);
            fputcsv($handle, [
                'ID',
                'Fecha',
                'Estado',
                'Tipo',
                'Cliente',
                'Teléfono',
                'Dirección',
                'Total',
                'Costo',
                'Descuento',
            ]);

            $query->chunk(500, function ($orders) use ($handle) {
                foreach ($orders as $order) {
                    fputcsv($handle, [
                        $order->id,
                        optional($order->ordered_at)->format('Y-m-d H:i:s'),
                        $order->status,
                        $order->order_type,
                        $order->client_name ?? '',
                        $order->client_phone ?? '',
                        $order->delivery_address ?? '',
                        $order->total,
                        $order->cost,
                        $order->discount_amount,
                    ]);
                }
            });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    /**
     * Exporta el historial de facturas como CSV con BOM UTF-8. Excluye facturas voided.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportInvoices(string $companyNit, array $filters): StreamedResponse
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

        if ($query->clone()->count() === 0) {
            throw new PdfEmptyDataException;
        }

        $filename = "facturas_{$this->today()}.csv";

        $response = new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, self::BOM);
            fputcsv($handle, [
                'ID',
                'Tipo',
                'Plan',
                'Período desde',
                'Período hasta',
                'Vencimiento',
                'Monto base',
                'Descuento %',
                'Descuento',
                'Total',
                'Estado',
                'Pagada el',
                'Referencia de pago',
            ]);

            $query->chunk(500, function ($invoices) use ($handle) {
                foreach ($invoices as $invoice) {
                    /** @var Invoice $invoice */
                    $payment = $invoice->payments()->first();
                    fputcsv($handle, [
                        $invoice->id,
                        $invoice->type,
                        $invoice->subscription?->plan?->name ?? '',
                        optional($invoice->period_from)->toDateString(),
                        optional($invoice->period_to)->toDateString(),
                        optional($invoice->due_date)->toDateString(),
                        $invoice->base_amount,
                        $invoice->discount_percent ?? '',
                        $invoice->discount_amount ?? '',
                        $invoice->amount,
                        $invoice->status,
                        optional($payment?->paid_at)->toDateTimeString() ?? '',
                        $payment?->payment_reference ?? '',
                    ]);
                }
            });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    private function today(): string
    {
        return Carbon::today()->format('Y-m-d');
    }
}
