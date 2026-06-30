<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CancellationRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\TableSession;
use App\Models\User;
use App\Support\OrderTotalCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lógica de la pantalla del mesero: aprobar tandas, rechazar,
 * editar notas, resolver cancelaciones, cerrar sesión.
 *
 * Reglas:
 *  - Toda mutación bajo `DB::transaction` + `lockForUpdate` sobre Order o
 *    TableSession.
 *  - Cuando se aprueba la primera tanda de la sesión, la sesión pasa a
 *    `locked` (no acepta nuevos comensales por defecto — el mesero puede
 *    re-habilitar con `accepts_new_guests=true`).
 *  - `orders.status` se promueve de `pending_approval` a `pending` cuando
 *    se aprueba el primer item.
 *  - Items con `status=in_kitchen` o posterior NO se pueden cancelar desde
 *    el cliente; el mesero usa una acción manual aparte con motivo obligatorio.
 *
 * Aislamiento por sede (#192): los `lockForUpdate` sobre `TableSession` usan
 * `withoutBranchScope()` por la misma razón que TableCashierService — el
 * filtro por `session_id` cierra el aislamiento y el caller ya validó la
 * sede al cargar la sesión bajo BranchScope.
 */
class TableWaiterService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TableSessionService $sessions,
        private readonly OrderTotalCalculator $totals,
    ) {}

    /**
     * Aprueba un conjunto de items (la "tanda" — items submitidos por uno o
     * varios comensales). Cada llamada a este método crea una **ORDEN NUEVA**
     * con los items aprobados, dejando la orden "buffer" para los siguientes
     * items que el comensal agregue.
     *
     * Modelo:
     *  - Buffer: `Order` con `status=pending_approval`, recibe items recién
     *    agregados/submitidos. Una por sesión, lazy-created por
     *    `TableOrderService::resolveOrderForSession`.
     *  - Aprobada: `Order` con `status=pending`, recibe los items aprobados
     *    en esta llamada. N por sesión (una por cada approve action del
     *    mesero). Visible en `/orders/board` y `/orders/cashier`.
     *
     * Mueve los items de la buffer → orden aprobada cambiando `order_id`.
     * Recalcula totales de ambas órdenes. Si la buffer queda vacía de items
     * pending_approval, se conserva (puede recibir items nuevos sin perder
     * historial); cuando el cliente cierre todo, la buffer se cerrará en
     * `closeEmpty`.
     *
     * Locks: sesión + buffer + items afectados (orden nueva no necesita lock
     * porque acaba de crearse).
     *
     * @param  list<int>  $itemIds
     * @return array{approved: int, session: TableSession, order: Order}
     */
    public function approveBatch(
        TableSession $session,
        array $itemIds,
        User $actor,
        Request $request,
    ): array {
        if ($itemIds === []) {
            throw new InvalidArgumentException('No seleccionaste items para aprobar.');
        }

        return DB::transaction(function () use ($session, $itemIds, $actor, $request) {
            /** @var TableSession $lockedSession */
            $lockedSession = TableSession::withoutBranchScope()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Order $buffer */
            $buffer = Order::withoutGlobalScopes()
                ->where('table_session_id', $lockedSession->id)
                ->where('status', 'pending_approval')
                ->lockForUpdate()
                ->firstOrFail();

            $items = OrderItem::query()
                ->whereIn('id', $itemIds)
                ->where('order_id', $buffer->id)
                ->where('status', 'pending_approval')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                // Distinguir doble-aprobación concurrente (idempotente) de request inválido.
                // Si alguno de los item_ids ya salió de pending_approval, significa que otra
                // llamada los procesó primero dentro de la misma ventana de 30s de polling.
                // En ese caso retornamos éxito silencioso para que el frontend refresque.
                $alreadyProcessed = OrderItem::query()
                    ->whereIn('id', $itemIds)
                    ->whereIn('status', ['approved', 'in_kitchen', 'ready', 'served', 'cancelled'])
                    ->exists();

                if ($alreadyProcessed) {
                    return ['approved' => 0, 'session' => $lockedSession, 'order' => $buffer];
                }

                throw new InvalidArgumentException('No hay items por aprobar en esa selección.');
            }

            $now = Carbon::now();

            // Crear orden NUEVA para esta tanda. Hereda contexto operativo
            // (company, branch, table) de la buffer + sesión. Status `pending`
            // = la cocina puede empezar; aparece en /orders/board.
            $newOrder = new Order;
            $newOrder->company_nit = $buffer->company_nit;
            $newOrder->branch_id = $buffer->branch_id;
            $newOrder->table_session_id = $lockedSession->id;
            $newOrder->session_id = $buffer->session_id;
            $newOrder->status = 'pending';
            $newOrder->order_type = 'table';
            $newOrder->table_number = $buffer->table_number;
            $newOrder->total = '0.00';
            $newOrder->subtotal = '0.00';
            $newOrder->ordered_at = $now;
            $newOrder->save();

            // Mover items aprobados de la buffer a la orden nueva.
            foreach ($items as $item) {
                $item->order_id = $newOrder->id;
                $item->status = 'approved';
                $item->approved_at = $now;
                $item->save();

                $this->audit->log(
                    'table.item.approved',
                    user: $actor,
                    auditable: $item,
                    data: [
                        'order_id' => $newOrder->id,
                        'buffer_order_id' => $buffer->id,
                        'table_session_id' => $lockedSession->id,
                        'guest_id' => $item->guest_id,
                    ],
                    request: $request,
                );
            }

            // Recalcular totales: orden nueva con sus items + buffer con lo
            // que haya quedado.
            $this->totals->recalculateAndSave($newOrder);
            $this->totals->recalculateAndSave($buffer);

            $this->audit->log(
                'table.batch.approved',
                user: $actor,
                auditable: $newOrder,
                data: [
                    'table_session_id' => $lockedSession->id,
                    'items_count' => $items->count(),
                    'item_ids' => $items->pluck('id')->all(),
                    'order_total' => (string) $newOrder->total,
                ],
                request: $request,
            );

            if ($lockedSession->status === 'open') {
                // Primera tanda aprobada → lockea la mesa.
                $this->sessions->lockSession($lockedSession);
                $lockedSession->refresh();
            }

            return [
                'approved' => $items->count(),
                'session' => $lockedSession,
                'order' => $newOrder->refresh(),
            ];
        });
    }

    /**
     * Rechaza un item (cancela con `cancellation_reason=waiter`). El mesero
     * puede explicar motivo (e.g. "ya no tenemos ese plato hoy").
     */
    public function rejectItem(
        OrderItem $item,
        TableSession $session,
        ?string $reason,
        User $actor,
        Request $request,
    ): OrderItem {
        $reason = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;

        return DB::transaction(function () use ($item, $session, $reason, $actor, $request) {
            /** @var OrderItem $locked */
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                return $locked;
            }

            if (! in_array($locked->status, ['pending_approval', 'approved'], true)) {
                throw new InvalidArgumentException(
                    'Este item ya entró a cocina — usá la acción "cancelar plato" para que quede en auditoría.'
                );
            }

            $locked->status = 'cancelled';
            $locked->cancellation_reason = 'waiter';
            $locked->cancelled_at = Carbon::now();
            $locked->save();

            /** @var Order $order */
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $this->totals->recalculateAndSave($order);

            $this->audit->log(
                'table.item.rejected_by_waiter',
                user: $actor,
                auditable: $locked,
                data: [
                    'order_id' => $locked->order_id,
                    'table_session_id' => $session->id,
                    'guest_id' => $locked->guest_id,
                    'reason' => $reason,
                ],
                request: $request,
            );

            return $locked;
        });
    }

    /**
     * El mesero cancela un item que ya está en cocina o posterior. Requiere
     * motivo. Queda en audit_log con `was_in_kitchen=true`.
     */
    public function cancelItemInKitchen(
        OrderItem $item,
        TableSession $session,
        string $reason,
        User $actor,
        Request $request,
    ): OrderItem {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new InvalidArgumentException('Es obligatorio explicar el motivo de la cancelación.');
        }

        return DB::transaction(function () use ($item, $session, $reason, $actor, $request) {
            /** @var OrderItem $locked */
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                return $locked;
            }

            $wasInKitchen = in_array($locked->status, ['in_kitchen', 'ready', 'served'], true);

            $locked->status = 'cancelled';
            $locked->cancellation_reason = 'waiter';
            $locked->cancelled_at = Carbon::now();
            $locked->save();

            /** @var Order $order */
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $this->totals->recalculateAndSave($order);

            $this->audit->log(
                'table.item.cancelled_by_waiter',
                user: $actor,
                auditable: $locked,
                data: [
                    'order_id' => $locked->order_id,
                    'table_session_id' => $session->id,
                    'guest_id' => $locked->guest_id,
                    'reason' => $reason,
                    'was_in_kitchen' => $wasInKitchen,
                ],
                request: $request,
            );

            return $locked;
        });
    }

    /**
     * Mesero edita las notas individuales de un item.
     */
    public function editItemNotes(
        OrderItem $item,
        TableSession $session,
        ?string $notes,
        User $actor,
        Request $request,
    ): OrderItem {
        $clean = $notes === null ? null : mb_substr(trim(strip_tags($notes)), 0, 500);
        $clean = $clean === '' ? null : $clean;

        return DB::transaction(function () use ($item, $session, $clean, $actor, $request) {
            /** @var OrderItem $locked */
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->notes === $clean) {
                return $locked;
            }

            $locked->notes = $clean;
            $locked->save();

            $this->audit->log(
                'table.item.notes_edited_by_waiter',
                user: $actor,
                auditable: $locked,
                data: [
                    'order_id' => $locked->order_id,
                    'table_session_id' => $session->id,
                ],
                request: $request,
            );

            return $locked;
        });
    }

    /**
     * Resuelve una solicitud de cancelación pendiente — `approved` cancela el
     * item con `cancellation_reason=waiter_approved`; `denied` solo deja el
     * resultado en auditoría sin tocar el item.
     */
    public function resolveCancellationRequest(
        CancellationRequest $cr,
        string $decision,
        ?string $reasonOverride,
        User $actor,
        Request $request,
    ): CancellationRequest {
        if (! in_array($decision, ['approved', 'denied'], true)) {
            throw new InvalidArgumentException('Decisión inválida.');
        }

        return DB::transaction(function () use ($cr, $decision, $reasonOverride, $actor, $request) {
            /** @var CancellationRequest $locked */
            $locked = CancellationRequest::query()->whereKey($cr->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                return $locked;
            }

            $locked->status = $decision;
            $locked->resolved_at = Carbon::now();
            $locked->resolved_by_user_id = $actor->id;
            if ($reasonOverride !== null && trim($reasonOverride) !== '') {
                $locked->reason = mb_substr(trim($reasonOverride), 0, 500);
            }
            $locked->save();

            if ($decision === 'approved') {
                /** @var OrderItem $item */
                $item = OrderItem::query()->whereKey($locked->order_item_id)->lockForUpdate()->firstOrFail();

                if ($item->status !== 'cancelled') {
                    $item->status = 'cancelled';
                    $item->cancellation_reason = 'waiter_approved';
                    $item->cancelled_at = Carbon::now();
                    $item->save();

                    /** @var Order $order */
                    $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
                    $this->totals->recalculateAndSave($order);
                }
            }

            $this->audit->log(
                $decision === 'approved' ? 'table.cancellation.approved' : 'table.cancellation.denied',
                user: $actor,
                auditable: $locked,
                data: [
                    'order_item_id' => $locked->order_item_id,
                    'reason' => $locked->reason,
                ],
                request: $request,
            );

            return $locked;
        });
    }

    /**
     * Libera una mesa cerrando su sesión grupal. Permitido en dos casos:
     *
     *  1. La orden no existe o no tiene items consumibles (mesa se levantó
     *     sin consumir). Caso clásico "cerrar como vacía".
     *  2. La orden ya está en estado terminal (revenue=completed o
     *     terminal_failure=cancelled/refunded/abandoned/failed). Significa
     *     que ya pasó por caja o se canceló contablemente, y la sesión
     *     solo queda como "cookie viva" del comensal — la mesa física ya
     *     está libre operativamente.
     *
     * Si hay items en producción (approved/in_kitchen/ready/served) y la
     * orden NO está en terminal, rechazamos: hay que pasar por caja para
     * emitir comprobante o cancelar contablemente primero.
     */
    public function closeEmpty(
        TableSession $session,
        User $actor,
        Request $request,
    ): TableSession {
        return DB::transaction(function () use ($session, $actor, $request) {
            /** @var TableSession $locked */
            $locked = TableSession::withoutBranchScope()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Una sesión puede tener N órdenes ahora (buffer + cada tanda
            // aprobada). Para "cerrar mesa vacía" exigimos que NINGUNA tenga
            // items consumibles sin pagar — si los tiene, el cajero debe
            // cobrarla antes.
            $orders = Order::withoutGlobalScopes()
                ->where('table_session_id', $locked->id)
                ->get(['id', 'status']);

            $terminalStatuses = array_merge(
                config('orders.revenue', ['completed']),
                config('orders.terminal_failure', ['cancelled', 'refunded', 'failed', 'abandoned']),
            );

            foreach ($orders as $order) {
                $orderClosed = in_array($order->status, $terminalStatuses, true);
                if ($orderClosed) {
                    continue;
                }

                $hasConsumable = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereIn('status', config('orders.item_statuses.consumable'))
                    ->exists();

                if ($hasConsumable) {
                    throw new InvalidArgumentException(
                        'La mesa tiene platos servidos o en cocina — pasala por caja antes de liberarla.'
                    );
                }
            }

            $this->sessions->closeSession($locked);

            $this->audit->log(
                'table.session.closed_empty',
                user: $actor,
                auditable: $locked,
                data: [
                    'table_session_id' => $locked->id,
                    'orders_count' => $orders->count(),
                    'order_statuses' => $orders->pluck('status')->all(),
                ],
                request: $request,
            );

            return $locked->refresh();
        });
    }

    /**
     * El mesero crea una nota desde su pantalla (group o kitchen_alert),
     * autor = User.
     */
    public function addNote(
        Order $order,
        string $scope,
        string $body,
        User $actor,
        Request $request,
    ): OrderNote {
        if (! in_array($scope, ['group', 'kitchen_alert'], true)) {
            throw new InvalidArgumentException('Scope de nota inválido.');
        }
        $body = mb_substr(trim($body), 0, 500);
        if ($body === '') {
            throw new InvalidArgumentException('La nota no puede ir vacía.');
        }

        return DB::transaction(function () use ($order, $scope, $body, $actor, $request) {
            $note = new OrderNote;
            $note->order_id = $order->id;
            $note->scope = $scope;
            $note->body = $body;
            $note->author()->associate($actor);
            $note->save();

            $this->audit->log(
                'table.note.added_by_waiter',
                user: $actor,
                auditable: $note,
                data: ['order_id' => $order->id, 'scope' => $scope],
                request: $request,
            );

            return $note;
        });
    }

    /**
     * Toggle de `accepts_new_guests` post-lock para permitir que nuevos
     * comensales se sumen a una sesión ya iniciada.
     */
    public function toggleAcceptsNewGuests(
        TableSession $session,
        bool $accepts,
        User $actor,
        Request $request,
    ): TableSession {
        return DB::transaction(function () use ($session, $accepts, $actor, $request) {
            /** @var TableSession $locked */
            $locked = TableSession::withoutBranchScope()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->accepts_new_guests === $accepts) {
                return $locked;
            }
            $locked->accepts_new_guests = $accepts;
            $locked->save();

            $this->audit->log(
                'table.session.accepts_new_guests_changed',
                user: $actor,
                auditable: $locked,
                data: ['accepts_new_guests' => $accepts],
                request: $request,
            );

            return $locked;
        });
    }
}
