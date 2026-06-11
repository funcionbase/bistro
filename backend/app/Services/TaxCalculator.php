<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Calcula el desglose tributario por línea y agregado por orden.
 *
 * Reglas (alineadas con CLAUDE.md / legislación CO):
 *  - Si tax_included_in_price = true: el `price` del menú es el final con impuesto.
 *      subtotal = total / (1 + rate/100); tax = total - subtotal.
 *  - Si tax_included_in_price = false: `price` es base gravable.
 *      subtotal = price * qty; tax = subtotal * rate/100; total = subtotal + tax.
 *
 * Cada línea persiste subtotal/tax/total ya calculados, así los reportes y el
 * desglose contable no recalculan en lectura.
 */
class TaxCalculator
{
    /**
     * Calcula el desglose para una línea (un ítem * cantidad).
     *
     * @return array{subtotal: float, tax_amount: float, total: float, tax_rate: float}
     */
    public function calculateLine(float $price, int $quantity, float $taxRate, bool $taxIncluded): array
    {
        $rate = max(0.0, $taxRate);
        $lineGross = round($price * $quantity, 2);

        if ($taxIncluded) {
            // El precio ya incluye el impuesto: total = lineGross.
            $total = $lineGross;
            $subtotal = $rate > 0 ? round($total / (1 + $rate / 100), 2) : $total;
            $taxAmount = round($total - $subtotal, 2);
        } else {
            // El precio es base; el impuesto se suma encima.
            $subtotal = $lineGross;
            $taxAmount = round($subtotal * ($rate / 100), 2);
            $total = round($subtotal + $taxAmount, 2);
        }

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'tax_rate' => round($rate, 2),
        ];
    }

    /**
     * Agrega la lista de líneas (cada una con subtotal/tax_amount/total) para
     * obtener los totales de la orden, junto con la tasa promedio efectiva.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{subtotal: float, tax_amount: float, total: float, effective_rate: float}
     */
    public function aggregate(array $lines): array
    {
        $subtotal = 0.0;
        $tax = 0.0;
        $total = 0.0;

        foreach ($lines as $line) {
            $subtotal += (float) ($line['subtotal'] ?? 0);
            $tax += (float) ($line['tax_amount'] ?? 0);
            $total += (float) ($line['total'] ?? 0);
        }

        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);
        $total = round($total, 2);

        $effectiveRate = $subtotal > 0 ? round(($tax / $subtotal) * 100, 2) : 0.0;

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => $total,
            'effective_rate' => $effectiveRate,
        ];
    }

    /**
     * Resuelve la tasa efectiva para una línea: tasa propia del ítem (`tax_rate`
     * en el menú) > tasa default de la empresa.
     */
    public function resolveRate(?float $itemRate, float $companyDefault): float
    {
        return $itemRate !== null ? max(0.0, $itemRate) : max(0.0, $companyDefault);
    }
}
