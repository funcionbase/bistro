<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * Recibo "térmico virtual" para WhatsApp (plan-mejoras-chat F4, CA2): texto
 * plano de 32 columnas envuelto en triple backtick para forzar monoespaciado.
 *
 * NO es el documento fiscal (ese es `Printing\DianReceiptBuilder` ESC/POS):
 * es el resumen que el cajero envía al cliente por el chat antes de aprobar.
 *
 * Reglas contables: el total sale de `orders.total` (jamás se re-suma);
 * propina separada (fuera del total); montos presentación CO sin decimales
 * (`number_format(v, 0, ',', '.')` — la BD sigue decimal:2).
 */
class WhatsappReceiptBuilder
{
    private const WIDTH = 32;

    /** Recibo listo para WhatsApp: contenido dentro de ``` ```. */
    public function buildForWhatsapp(Order $order, Company $company, ?string $clientName = null): string
    {
        return "```\n".$this->build($order, $company, $clientName)."\n```";
    }

    /** Recibo en texto plano de 32 columnas. */
    public function build(Order $order, Company $company, ?string $clientName = null): string
    {
        $order->loadMissing('orderItems');

        $lines = [];

        // Cabecera: nombre comercial + NIT (formato DianReceiptBuilder).
        $lines[] = $this->center(mb_strtoupper($this->clean((string) $company->commercial_name)));
        $lines[] = $this->center('NIT: '.$company->nit.($company->dv ? '-'.$company->dv : ''));
        $shortCode = strtoupper(substr((string) $order->id, 0, 4));
        $lines[] = $this->center('Pedido #'.$shortCode.' · '.$order->ordered_at?->format('d/m H:i'));
        $lines[] = str_repeat('-', self::WIDTH);

        // Datos del cliente y la entrega.
        if ($clientName !== null && trim($clientName) !== '') {
            $lines = array_merge($lines, $this->wrap('CLIENTE: '.$this->clean($clientName)));
        }
        if ($order->client_phone) {
            $phone = (string) $order->client_phone;
            $local = str_starts_with($phone, '57') && strlen($phone) === 12 ? substr($phone, 2) : $phone;
            $lines[] = 'TEL: '.$local;
        }
        $lines[] = 'TIPO: '.match ($order->order_type) {
            'delivery' => 'DOMICILIO',
            'pickup' => 'PARA LLEVAR',
            default => 'EN SITIO',
        };
        if ($order->payment_preference !== null) {
            $label = (string) (config('payments.labels')[$order->payment_preference] ?? $order->payment_preference);
            $lines[] = 'PAGO: '.mb_strtoupper($label);
        }
        if ($order->delivery_address) {
            $lines = array_merge($lines, $this->wrap('DIR: '.$this->clean((string) $order->delivery_address)));
        }
        if ($order->customer_notes) {
            $lines = array_merge($lines, $this->wrap('NOTAS: '.$this->clean((string) $order->customer_notes)));
        }
        $lines[] = str_repeat('-', self::WIDTH);

        // Items: filas order_items no canceladas (incluye la línea Domicilio).
        $lines[] = sprintf('%-10s %3s %8s %8s', 'PRODUCTO', 'UDS', 'PRECIO', 'TOTAL');
        foreach ($order->orderItems->where('status', '!=', 'cancelled') as $item) {
            /** @var OrderItem $item */
            $name = $this->clean((string) $item->name);
            $qty = (int) $item->quantity;
            $price = $this->money((float) $item->unit_price);
            $lineTotal = $this->money(round((float) $item->unit_price * $qty, 2));

            if (mb_strwidth($name) <= 10) {
                // Pad manual por ANCHO (no sprintf %-10s, que pádea por bytes y
                // desalinea nombres con tildes/ñ en UTF-8).
                $padded = $name.str_repeat(' ', 10 - mb_strwidth($name));
                $lines[] = sprintf('%s %3d %8s %8s', $padded, $qty, $price, $lineTotal);
            } else {
                // Nombre largo: línea propia + bloque numérico alineado debajo.
                $lines = array_merge($lines, $this->wrap($name));
                $lines[] = sprintf('%-10s %3d %8s %8s', '', $qty, $price, $lineTotal);
            }
        }
        $lines[] = str_repeat('-', self::WIDTH);

        // Totales — SIEMPRE desde orders.total (invariante contable).
        $total = (float) $order->total;
        $tip = (float) $order->tip_amount;

        // Cupón: las líneas de arriba van a precio BRUTO y el total es neto —
        // sin esta línea el recibo "no sumaba" para el cliente.
        if ((float) $order->discount_amount > 0) {
            $lines[] = $this->kv('DESCUENTO', '-'.$this->money((float) $order->discount_amount));
        }

        if ($tip > 0) {
            $lines[] = $this->kv('TOTAL SIN PROPINA', $this->money($total));
            $lines[] = $this->kv('PROPINA VOLUNTARIA', $this->money($tip));
            $lines[] = $this->kv('TOTAL A PAGAR', $this->money(round($total + $tip, 2)));
        } elseif ($order->tax_included_in_price) {
            $lines[] = $this->kv('TOTAL IVA INCLUIDO', $this->money($total));
        } else {
            $lines[] = $this->kv('SUBTOTAL', $this->money((float) $order->subtotal));
            $lines[] = $this->kv('IVA', $this->money((float) $order->tax_amount));
            $lines[] = $this->kv('TOTAL', $this->money($total));
        }

        // Bloque efectivo: solo si el cliente dijo con cuánto paga (CA7).
        if ($order->cash_pays_with !== null && (float) $order->cash_pays_with > 0) {
            $paysWith = (float) $order->cash_pays_with;
            $change = round($paysWith - ($total + $tip), 2);
            $lines[] = str_repeat('-', self::WIDTH);
            $lines[] = $this->kv('PAGA CON:', $this->money($paysWith));
            if ($change >= 0) {
                $lines[] = $this->kv('CAMBIO (DEVUELTAS):', $this->money($change));
            }
        }

        return implode("\n", $lines);
    }

    /** Presentación CO sin decimales; la BD sigue decimal:2. */
    private function money(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /** Etiqueta a la izquierda, monto alineado a la derecha (32 cols). */
    private function kv(string $label, string $amount): string
    {
        $pad = max(1, self::WIDTH - mb_strwidth($label) - mb_strwidth($amount));

        return $label.str_repeat(' ', $pad).$amount;
    }

    private function center(string $text): string
    {
        $text = mb_strimwidth($text, 0, self::WIDTH, '');
        $pad = max(0, intdiv(self::WIDTH - mb_strwidth($text), 2));

        return str_repeat(' ', $pad).$text;
    }

    /**
     * Corta texto a líneas de 32 cols por palabra.
     *
     * @return list<string>
     */
    private function wrap(string $text): array
    {
        return explode("\n", wordwrap($text, self::WIDTH, "\n", true));
    }

    /**
     * El contenido va dentro de ``` en WhatsApp y podría ir a impresoras:
     * strip de ESC/GS (`\x1B`/`\x1D`) y de backticks (romperían el bloque).
     */
    private function clean(string $text): string
    {
        return trim(str_replace(['`', "\x1B", "\x1D"], '', $text));
    }
}
