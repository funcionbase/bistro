<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\TaxCalculator;

/**
 * Helper único para recalcular los totales de una orden a partir de sus filas
 * `order_items`.
 *
 * Consolidación: `order_items` es la FUENTE de líneas y `orders.items`
 * JSON es una proyección de lectura que este helper reconstruye en cada
 * recálculo (mismo formato que las líneas de caja: id/name/price/cost/
 * quantity/category/notes + desglose tributario). Así los consumidores del
 * JSON (PDFs, comanda, DIAN, inventario, métricas de food cost) ven las
 * órdenes QR igual que las de caja.
 *
 * El desglose por línea lo produce `TaxCalculator` — el MISMO servicio del
 * flujo de caja (`OrderController::buildOrderLines`/`appendItems`) — usando el
 * snapshot tributario de la orden (`tax_included_in_price`,
 * `snapshot_default_tax_rate`), NUNCA el estado vivo de la empresa: si el
 * régimen cambió con la mesa abierta, la cuenta conserva el régimen con el que
 * nació. Tasa por línea: `order_items.tax_rate` con fallback al default
 * snapshoteado para filas legacy.
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
     * Recalcula y persiste `subtotal`, `tax_amount`, `tax_rate`, `total`,
     * `cost` y la proyección `orders.items` JSON. Asume que el caller ya hizo
     * `Order::lockForUpdate()` dentro de una transacción. Devuelve el `total`
     * persistido (`decimal:2`).
     *
     * Guarda: si la orden NO tiene ninguna fila `order_items` pero sí líneas
     * JSON, es una orden legacy anterior al dual-write — no se toca (recalcular
     * desde cero filas colapsaría el total a 0 y borraría la historia).
     */
    public function recalculateAndSave(Order $order): string
    {
        $hasRows = OrderItem::query()->where('order_id', $order->id)->exists();
        if (! $hasRows && ! empty($order->items)) {
            return (string) $order->total;
        }

        $lines = $this->buildProjectedLines($order);
        $aggregate = $this->taxes->aggregate($lines);

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
        $order->items = $lines;
        $order->cost = $this->computeCost($lines);
        $order->save();

        return (string) $order->total;
    }

    /**
     * Proyecta los items no-cancelados como líneas en el formato canónico de
     * `orders.items` JSON (paridad con `OrderController::buildOrderLines`),
     * con desglose tributario por línea vía `TaxCalculator`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildProjectedLines(Order $order): array
    {
        $taxIncluded = (bool) ($order->tax_included_in_price ?? true);
        $defaultRate = (float) ($order->snapshot_default_tax_rate ?? 0);

        return OrderItem::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get()
            ->map(function (OrderItem $item) use ($taxIncluded, $defaultRate): array {
                $breakdown = $this->taxes->calculateLine(
                    (float) $item->unit_price,
                    (int) $item->quantity,
                    $this->taxes->resolveRate($item->tax_rate !== null ? (float) $item->tax_rate : null, $defaultRate),
                    $taxIncluded,
                );

                return [
                    'id' => (string) $item->menu_item_id,
                    'name' => (string) $item->name,
                    'price' => (float) $item->unit_price,
                    'cost' => $item->unit_cost !== null ? (float) $item->unit_cost : null,
                    'quantity' => (int) $item->quantity,
                    'category' => (string) ($item->category ?? ''),
                    'notes' => $item->notes,
                    'tax_rate' => $breakdown['tax_rate'],
                    'subtotal' => $breakdown['subtotal'],
                    'tax_amount' => $breakdown['tax_amount'],
                    'total' => $breakdown['total'],
                ];
            })
            ->all();
    }

    /**
     * Costo agregado desde las líneas proyectadas. Líneas con cost=null se
     * omiten; null si ninguna tiene costo (espejo de
     * `OrderController::computeOrderCost` — no se asume 0 para no falsear el
     * food cost).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function computeCost(array $lines): ?float
    {
        $total = 0.0;
        $hasCost = false;
        foreach ($lines as $line) {
            if ($line['cost'] === null) {
                continue;
            }
            $hasCost = true;
            $total += (float) $line['cost'] * (int) $line['quantity'];
        }

        return $hasCost ? round($total, 2) : null;
    }
}
