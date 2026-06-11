<?php

namespace App\Services\Printing;

use App\Models\Order;
use App\Services\CompanySettingsService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Renderiza el recibo de venta (ESC/POS) de una orden ya pagada.
 *
 * Solo lectura. La fuente de verdad de los montos cobrados son los
 * PaymentReceipts (inmutables, ver CLAUDE.md): el net por método se
 * calcula como SUM(amount) GROUP BY payment_method, no a partir de
 * orders.total. Re-imprimir es idempotente y NO genera nuevos asientos
 * contables; si se solicita con $copy=true, marca el ticket como COPIA.
 *
 * Configuración leída de company_settings:
 *  - printing.receipt_width  (58|80, default 58)
 *  - printing.header_lines   (json[], líneas extra entre nombre y orden)
 *  - printing.footer_message (string, mensaje de cierre)
 *  - printing.show_qr_menu   (bool, no implementado aún — placeholder)
 */
class ReceiptPrintingService
{
    public function __construct(private readonly CompanySettingsService $settings) {}

    public function render(Order $order, bool $copy = false, ?int $widthOverride = null): string
    {
        $order->loadMissing(['company', 'receipts']);
        $company = $order->company;

        $companySettings = $company ? $this->settings->all($company->nit) : [];
        $width = $widthOverride ?? (int) ($companySettings['printing.receipt_width'] ?? 58);
        $headerLines = (array) ($companySettings['printing.header_lines'] ?? []);
        $footerMessage = (string) ($companySettings['printing.footer_message'] ?? '¡Gracias por tu visita!');

        $b = EscposBuilder::forWidth($width)->init();

        $this->renderHeader($b, $order, $company, $headerLines, $copy);
        $this->renderItems($b, $order);
        $this->renderTotals($b, $order);
        $this->renderPayments($b, $order->receipts);
        $this->renderFooter($b, $footerMessage);

        return $b->cut()->getBuffer();
    }

    private function renderHeader(EscposBuilder $b, Order $order, $company, array $headerLines, bool $copy): void
    {
        $b->alignCenter()->boldOn()->doubleSize();
        $b->text(mb_strtoupper($company->commercial_name ?? 'RESTAURANTE'));
        $b->normalSize()->boldOff();

        if ($company?->nit) {
            $b->text('NIT: '.$company->nit);
        }

        foreach ($headerLines as $line) {
            if (is_string($line) && trim($line) !== '') {
                $b->text($line);
            }
        }

        $tz = config('orders.timezone', 'America/Bogota');
        $when = ($order->ordered_at ?? Carbon::now())->copy()->timezone($tz)->format('d/m/Y H:i');
        $b->text($when);
        $b->boldOn()->text('Orden #'.$order->id)->boldOff();

        if ($copy) {
            $b->boldOn()->text('*** COPIA ***')->boldOff();
        }

        $b->alignLeft()->rule();
    }

    private function renderItems(EscposBuilder $b, Order $order): void
    {
        $items = $order->items ?? [];

        foreach ($items as $item) {
            $qty = (int) ($item['quantity'] ?? 1);
            $name = (string) ($item['name'] ?? ($item['id'] ?? '—'));
            $unit = (float) ($item['price'] ?? 0);
            $total = $qty * $unit;

            $b->twoCols($qty.'x '.$name, $this->money($total));

            if (! empty($item['notes'])) {
                $b->text('   '.$item['notes']);
            }
        }

        $b->rule();
    }

    private function renderTotals(EscposBuilder $b, Order $order): void
    {
        $subtotal = (float) ($order->subtotal ?? 0);
        $discount = (float) ($order->discount_amount ?? 0);
        $tax = (float) ($order->tax_amount ?? 0);
        $tip = (float) ($order->tip_amount ?? 0);
        $total = (float) $order->total;

        if ($subtotal > 0) {
            $b->twoCols('Subtotal', $this->money($subtotal));
        }

        if ($discount > 0) {
            $label = 'Descuento'.($order->coupon_code ? ' '.$order->coupon_code : '');
            $b->twoCols($label, '-'.$this->money($discount));
        }

        if ($tax > 0) {
            $rate = $order->tax_rate ? ' '.rtrim(rtrim(number_format((float) $order->tax_rate, 2, '.', ''), '0'), '.').'%' : '';
            $b->twoCols('Impuesto'.$rate, $this->money($tax));
        }

        if ($tip > 0) {
            $b->twoCols('Propina', $this->money($tip));
        }

        $b->rule();
        $b->boldOn()->doubleSize();
        $b->twoCols('TOTAL', $this->money($total));
        $b->normalSize()->boldOff();
    }

    private function renderPayments(EscposBuilder $b, Collection $receipts): void
    {
        $byMethod = $receipts->groupBy('payment_method');

        if ($byMethod->isEmpty()) {
            return;
        }

        $b->rule();

        foreach ($byMethod as $method => $rows) {
            $sum = (float) $rows->sum(fn ($r) => (float) $r->amount);
            $label = 'Pago: '.$this->methodLabel((string) $method);
            $ref = $rows->pluck('reference')->filter()->first();

            $b->twoCols($label, $this->money($sum));

            if ($ref) {
                $b->text('   ref '.$ref);
            }
        }
    }

    private function renderFooter(EscposBuilder $b, string $footerMessage): void
    {
        $b->feed(1)->alignCenter()->text($footerMessage)->alignLeft();
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'efectivo',
            'card' => 'tarjeta',
            'transfer' => 'transferencia',
            'refund' => 'devolución',
            default => $method,
        };
    }

    private function money(float $amount): string
    {
        // En facturación al consumidor final colombiano se acostumbra mostrar
        // sin decimales (decisión de presentación; la BD sigue en decimal:2).
        $sign = $amount < 0 ? '-' : '';
        $abs = abs($amount);

        return $sign.'$'.number_format($abs, 0, ',', '.');
    }
}
