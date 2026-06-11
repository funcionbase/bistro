<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\KdsStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Transiciones de estado de un `order_item` desde la pantalla del cocinero.
 *
 * Máquina forward-only por item:
 *   approved → in_kitchen → ready → served
 *
 * Cada paso registra timestamp dedicado y audit log. Si la orden está en
 * `pending` y todos sus items están `ready`, la promueve a `ready`. Si todos
 * están `served`, el cierre de pago de caja la promueve a `completed`.
 *
 * Inventario: la transición `approved → in_kitchen` es donde se consume
 * insumos. La idempotencia se asegura porque solo items con status=approved
 * pasan a in_kitchen — si ya está in_kitchen, la llamada es no-op silencioso.
 */
class KdsTicketService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * approved → in_kitchen. Setea `in_kitchen_at`. Si la orden estaba en
     * `pending` y al menos un item pasó a `in_kitchen`, promueve el order
     * a `in_kitchen` para que el cambio se vea en /orders/board (kanban).
     * Sin esto el cajero veía la orden estancada en "Pendiente" aunque
     * cocina ya la estuviera preparando.
     */
    public function markInKitchen(OrderItem $item, User $actor, Request $request): OrderItem
    {
        $updated = $this->transition($item, 'approved', 'in_kitchen', 'in_kitchen_at', 'kds.item.in_kitchen', $actor, $request);

        $this->maybePromoteOrderStatus($updated->order_id);

        return $updated;
    }

    /**
     * in_kitchen → ready. Setea `ready_at`. Si todos los items consumibles
     * de la orden están `ready` o `served`, promueve `orders.status=ready`.
     */
    public function markReady(OrderItem $item, User $actor, Request $request): OrderItem
    {
        $updated = $this->transition($item, 'in_kitchen', 'ready', 'ready_at', 'kds.item.ready', $actor, $request);

        $this->maybePromoteOrderStatus($updated->order_id);

        return $updated;
    }

    /**
     * ready → served. Setea `served_at`. La mayoría de meseros usa esta
     * acción desde la pantalla de mesero, pero el KDS también puede marcarlo
     * cuando entrega el plato directamente al comensal.
     */
    public function markServed(OrderItem $item, User $actor, Request $request): OrderItem
    {
        return $this->transition($item, 'ready', 'served', 'served_at', 'kds.item.served', $actor, $request);
    }

    /**
     * Transición forward-only con lock pesimista sobre el item.
     */
    private function transition(
        OrderItem $item,
        string $fromStatus,
        string $toStatus,
        string $timestampColumn,
        string $auditAction,
        User $actor,
        Request $request,
    ): OrderItem {
        return DB::transaction(function () use ($item, $fromStatus, $toStatus, $timestampColumn, $auditAction, $actor, $request) {
            /** @var OrderItem $locked */
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $toStatus) {
                return $locked;
            }

            if ($locked->status !== $fromStatus) {
                throw new InvalidArgumentException(
                    sprintf('No se puede pasar de "%s" a "%s".', $locked->status, $toStatus)
                );
            }

            $locked->status = $toStatus;
            $locked->setAttribute($timestampColumn, Carbon::now());
            $locked->save();

            $this->audit->log(
                $auditAction,
                user: $actor,
                auditable: $locked,
                data: [
                    'order_id' => $locked->order_id,
                    'from' => $fromStatus,
                    'to' => $toStatus,
                ],
                request: $request,
            );

            return $locked;
        });
    }

    /**
     * Si todos los items de la orden que pertenecen a la estación
     * dada quedaron en `ready` o `served`, emite audit `kds.station_ready`
     * (la promoción global a `orders.status=ready` la sigue manejando
     * `maybePromoteOrderStatus`, que ya corre al final de `markReady`).
     *
     * El cálculo de "pertenece a la estación" usa el mapa `menu_item_id →
     * kds_station_id` del menú activo. Items sin mapeo caen al `default`
     * de la sede; el caller (`KdsController::transitionForStation`) ya
     * resolvió `defaultStationId`.
     *
     * Envuelto en `DB::transaction` + `lockForUpdate` sobre la orden para
     * serializar contra otra tableta de otra estación que cierre la
     * suya al mismo tiempo — sin lock dos estaciones podrían emitir audit
     * `kds.station_ready` con state inconsistente del recuento de items
     * `consumibles`.
     *
     * @param  array<string, int|null>  $stationMap
     */
    public function maybeMarkStationReady(
        string $orderId,
        KdsStation $station,
        array $stationMap,
        ?string $defaultStationId,
        User $actor,
        Request $request,
    ): void {
        DB::transaction(function () use ($orderId, $station, $stationMap, $defaultStationId, $actor, $request) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null) {
                return;
            }

            $items = OrderItem::query()
                ->where('order_id', $orderId)
                ->whereIn('status', config('orders.item_statuses.consumable'))
                ->get();

            $stationItems = $items->filter(function (OrderItem $item) use ($stationMap, $station, $defaultStationId) {
                $explicit = $stationMap[(string) $item->menu_item_id] ?? null;
                $effective = $explicit ?? $defaultStationId;

                return $effective === $station->id;
            });

            if ($stationItems->isEmpty()) {
                return;
            }

            $stillPending = $stationItems->contains(
                fn (OrderItem $i) => ! in_array($i->status, ['ready', 'served'], true)
            );

            if ($stillPending) {
                return;
            }

            $this->audit->log(
                'kds.station_ready',
                user: $actor,
                auditable: $order,
                data: [
                    'order_id' => $order->id,
                    'station_id' => $station->id,
                    'station_slug' => $station->slug,
                    'items_ready' => $stationItems->count(),
                ],
                request: $request,
            );
        });
    }

    /**
     * Si todos los items consumibles de la orden están `ready` o `served`,
     * promueve la orden a `ready`. Esto sirve para que el mesero/caja vean
     * que la mesa ya tiene todo listo.
     */
    private function maybePromoteOrderStatus(string $orderId): void
    {
        /** @var Order $order */
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        if ($order === null) {
            return;
        }

        if (! in_array($order->status, ['pending', 'in_kitchen'], true)) {
            return;
        }

        $consumableStatuses = config('orders.item_statuses.consumable');

        $remaining = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereIn('status', $consumableStatuses)
            ->where('status', '!=', 'ready')
            ->where('status', '!=', 'served')
            ->exists();

        if ($remaining) {
            // Algunos siguen en cocina/approved — promovemos al menos a in_kitchen.
            if ($order->status === 'pending') {
                $hasInKitchen = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->where('status', 'in_kitchen')
                    ->exists();
                if ($hasInKitchen) {
                    $order->status = 'in_kitchen';
                    $order->save();
                }
            }

            return;
        }

        $order->status = 'ready';
        $order->save();
    }
}
