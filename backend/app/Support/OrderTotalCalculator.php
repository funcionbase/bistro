<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Helper único para recalcular `orders.total` a partir de `order_items` (#191).
 *
 * Regla contable: `total = SUM(order_items.unit_price * quantity)` excluyendo
 * items con `status='cancelled'`. Persistido en DB con `decimal:2`. Usado por
 * el flujo de carrito del comensal, el mesero al editar y la caja al cobrar.
 *
 * NO debe consumirse desde reportes financieros — esos calculan agregaciones
 * en SQL directamente.
 */
class OrderTotalCalculator
{
    /**
     * Calcula el total agregando los items no-cancelados del order_id dado.
     *
     * La suma se hace en SQL (`SUM(unit_price * quantity)`) para evitar el
     * costo de hidratar todos los items en PHP — y porque el redondeo lo
     * decide PostgreSQL con `numeric(12,2)`, consistente con `decimal:2`.
     */
    public function computeForOrderId(string $orderId): string
    {
        $sum = (string) (OrderItem::query()
            ->where('order_id', $orderId)
            ->where('status', '!=', 'cancelled')
            ->select(DB::raw('COALESCE(SUM(unit_price * quantity), 0) AS total'))
            ->value('total') ?? '0');

        return number_format((float) $sum, 2, '.', '');
    }

    /**
     * Recalcula y persiste `total` en la orden. Asume que el caller ya hizo
     * `Order::lockForUpdate()` dentro de una transacción.
     */
    public function recalculateAndSave(Order $order): string
    {
        $total = $this->computeForOrderId($order->id);

        $order->total = $total;
        $order->save();

        return $total;
    }
}
