<?php

namespace App\Jobs;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Job de cola: genera el HTML del informe de pedidos y almacena el token de descarga en caché.
 *
 * Tries: 3, Timeout: 120s. El archivo se guarda en {disk}/reports/{companyNit}/{downloadToken}.html.
 * Registra el token en Cache con TTL de config('reports.download_ttl', 30) minutos.
 * El disco se toma de config('reports.storage_disk', 'local').
 * Genera HTML en lugar de PDF nativo (requiere barryvdh/laravel-dompdf para PDF real).
 *
 * @env reports.storage_disk — disco de almacenamiento (local por defecto)
 * @env reports.download_ttl — TTL en minutos del enlace de descarga (30 por defecto)
 */
class GenerateReportPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $companyNit,
        public readonly Carbon $dateFrom,
        public readonly Carbon $dateTo,
        public readonly string $status,
        public readonly string $downloadToken,
    ) {}

    public function handle(): void
    {
        $query = Order::where('company_nit', $this->companyNit)
            ->where('status', '!=', 'pending_approval')
            ->whereBetween('ordered_at', [$this->dateFrom->startOfDay(), $this->dateTo->endOfDay()]);

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        $orders = $query->orderByDesc('ordered_at')->get();

        $summary = [
            'total_orders' => $orders->count(),
            'completed' => $orders->whereIn('status', config('orders.revenue'))->count(),
            'cancelled' => $orders->where('status', 'cancelled')->count(),
            'refunded' => $orders->where('status', 'refunded')->count(),
            'abandoned' => $orders->where('status', 'abandoned')->count(),
            'total_revenue' => $orders->whereIn('status', config('orders.revenue'))->sum('total'),
        ];

        // Generate HTML report (install barryvdh/laravel-dompdf for true PDF output)
        $html = $this->buildHtml($summary, $orders);

        $disk = config('reports.storage_disk', 'local');
        $path = "reports/{$this->companyNit}/{$this->downloadToken}.html";

        Storage::disk($disk)->put($path, $html);

        $ttl = (int) config('reports.download_ttl', 30);

        Cache::put("report_download:{$this->downloadToken}", [
            'path' => $path,
            'disk' => $disk,
            'company_nit' => $this->companyNit,
        ], now()->addMinutes($ttl));
    }

    /** @param  Collection<int, Order>  $orders */
    private function buildHtml(array $summary, Collection $orders): string
    {
        $from = $this->dateFrom->format('Y-m-d');
        $to = $this->dateTo->format('Y-m-d');
        $rows = $orders->map(fn (Order $o) => sprintf(
            '<tr><td>%s</td><td>%s</td><td>$%s</td></tr>',
            $o->ordered_at->format('Y-m-d H:i'),
            $o->status,
            number_format((float) $o->total, 2),
        ))->implode("\n");

        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head><meta charset="UTF-8"><title>Informe de Pedidos</title></head>
        <body>
        <h1>Informe de Pedidos — {$this->companyNit}</h1>
        <p>Período: {$from} a {$to}</p>
        <h2>Resumen</h2>
        <ul>
            <li>Total pedidos: {$summary['total_orders']}</li>
            <li>Completados: {$summary['completed']}</li>
            <li>Cancelados: {$summary['cancelled']}</li>
            <li>Devoluciones: {$summary['refunded']}</li>
            <li>Abandonados: {$summary['abandoned']}</li>
            <li>Ingresos: \${$summary['total_revenue']}</li>
        </ul>
        <h2>Detalle de Pedidos</h2>
        <table border="1" cellpadding="4">
            <thead><tr><th>Fecha</th><th>Estado</th><th>Total</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
        </body>
        </html>
        HTML;
    }
}
