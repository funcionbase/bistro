<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\TaxCalculator;

/**
 * Helper único para recalcular los totales de una orden a partir de sus filas
 * `order_items` (#191, #293).
 *
 * Consolidación #293: además de `total`, ahora calcula `subtotal`, `tax_amount`
 * y `tax_rate` (tasa efectiva) delegando el desglose por línea en
 * `TaxCalculator` — el MISMO servicio que usa el flujo de caja
 * (`OrderController::buildOrderLines`/`appendItems`). El desglose usa el
 * snapshot tributario de la orden (`tax_included_in_price`,
 * `snapshot_default_tax_rate`), NUNCA el estado vivo de la empresa: si el
 * régimen cambió con la mesa abierta, la cuenta conserva el régimen con el que
 * nació (paridad con `appendItems`).
 *
 * Tasa por línea: `order_items.tax_rate` (snapshot al crear la línea) con
 * fallback a `orders.snapshot_default_tax_rate` para filas legacy sin snapshot.
 *
 * Si la orden tiene cupón (`discount_amount > 0`), el neto se prorratea igual
 * que en `appendItems`: `total` queda neto del descuento y
 * `tax = total - subtotal` absorbe el redondeo (invariante §13).
 *
 * NO debe consumirse desde reportes financieros — esos calculan agregaciones
 * en SQL directamente.
 */
class OrderTotalCalculator
{
    public function __construct(private readonly TaxCalculator $taxes) {}

    /**
     * Calcula el desglose agregando los items no-cancelados de la orden, línea
     * por línea (mismo redondeo por línea que el flujo de caja).
     *
     * @return array{subtotal: float, tax_amount: float, total: float, effective_rate: float}
     */
    public function computeForOrder(Order $order): array
    {
        $taxIncluded = (bool) ($order->tax_included_in_price ?? true);
        $defaultRate = (float) ($order->snapshot_default_tax_rate ?? 0);

        $lines = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->get(['unit_price', 'quantity', 'tax_rate'])
            ->map(fn (OrderItem $item): array => $this->taxes->calculateLine(
                (float) $item->unit_price,
                (int) $item->quantity,
                $this->taxes->resolveRate($item->tax_rate !== null ? (float) $item->tax_rate : null, $defaultRate),
                $taxIncluded,
            ))
            ->all();

        return $this->taxes->aggregate($lines);
    }

    /**
     * Recalcula y persiste `subtotal`, `tax_amount`, `tax_rate` y `total` en la
     * orden. Asume que el caller ya hizo `Order::lockForUpdate()` dentro de una
     * transacción. Devuelve el `total` persistido (`decimal:2`).
     */
    public function recalculateAndSave(Order $order): string
    {
        $aggregate = $this->computeForOrder($order);

        $discount = min((float) $order->discount_amount, $aggregate['total']);
        if ($discount > 0 && $aggregate['total'] > 0) {
            $netTotal = round($aggregate['total'] - $discount, 2);
            $order->subtotal = round($aggregate['subtotal'] * ($netTotal / $aggregate['total']), 2);
            $order->tax_amount = round($netTotal - (float) $order->subtotal, 2);
            $order->total = $netTotal;
        } else {
            $order->subtotal = $aggregate['subtotal'];
            $order->tax_amount = $aggregate['tax_amount'];
            $order->total = $aggregate['total'];
        }
        $order->tax_rate = $aggregate['effective_rate'];
        $order->save();

        return (string) $order->total;
    }
}
