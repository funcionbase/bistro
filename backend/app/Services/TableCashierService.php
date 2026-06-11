<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentReceipt;
use App\Models\TableSession;
use App\Models\TableSessionGuest;
use App\Models\User;
use App\Support\OrderTotalCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Caja con desglose por comensal y pago dividido sobre sesiones de mesa
 * con QR.
 *
 * Reglas contables (CLAUDE.md):
 *  - `PaymentReceipt` inmutable; refund = receipt con `amount` negativo
 *    y mismo `guest_id`.
 *  - `payment_method` ∈ {cash, card, transfer, refund} (lista cerrada).
 *  - `amount` SIGNED; cobros positivos, refunds negativos.
 *  - Idempotencia por `client_uuid` (unique partial index existente).
 *  - Toda mutación bajo `DB::transaction` + lock sobre TableSession+Order.
 *  - Propina (`tip_amount`) sigue en columna separada de `orders` —
 *    nunca suma a `orders.total` ni a la base gravable.
 *
 * Naming: una orden por sesión. Los receipts pueden ser uno o muchos por
 * orden — uno por comensal en pay-partial, uno único en pay-all.
 *
 * Aislamiento por sede (#192): el caller (TableCashierController) corre bajo
 * JWT con `active_branch_id`. Las queries de `lockForUpdate` sobre
 * `TableSession` y `PaymentReceipt` usan `withoutBranchScope()` para no
 * depender del scope en el contexto bloqueante (evita inconsistencias si
 * el lock corre dentro de un job sin request). El filtro por `session_id`
 * o `client_uuid` ya garantiza precisión y la sede se valida en el caller
 * vía `BranchScope` natural al cargar la sesión.
 */
class TableCashierService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TableSessionService $sessions,
        private readonly CashRegisterService $cashRegister,
        private readonly OrderTotalCalculator $totals,
    ) {}

    /**
     * Estado de caja para una sesión: comensales con items consumibles +
     * unpaid_total, paid_total, tip_total, receipts existentes.
     *
     * @return array<string, mixed>
     */
    public function getSessionState(TableSession $session): array
    {
        // Una sesión puede tener N órdenes: una buffer (`pending_approval`)
        // + las que se materializan por cada tanda aprobada + las que el
        // cajero suma desde /orders/cashier seleccionando esta mesa.
        // Consolidamos TODAS las operativas (excepto la buffer) para el
        // desglose de cobro.
        $orders = Order::query()
            ->withoutGlobalScopes()
            ->where('table_session_id', $session->id)
            ->where('status', '!=', 'pending_approval')
            ->get();

        if ($orders->isEmpty()) {
            return [
                'order' => null,
                'guests' => [],
                'unpaid_total' => '0.00',
                'paid_total' => '0.00',
                'tip_total' => '0.00',
                'receipts' => [],
            ];
        }

        $orderIds = $orders->pluck('id')->all();
        $consumableStatuses = config('orders.item_statuses.consumable');

        $items = OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', $consumableStatuses)
            ->get();

        $guests = TableSessionGuest::query()
            ->where('table_session_id', $session->id)
            ->get();

        $receipts = PaymentReceipt::withoutBranchScope()
            ->whereIn('order_id', $orderIds)
            ->orderBy('paid_at')
            ->get();

        // Para el payload exterior `order` (compatibilidad con la UI) tomamos
        // la orden principal "más reciente" — la UI legacy lee `order.id` y
        // `order.total` para mostrar header. Sumamos totales arriba para el
        // valor de cobro real.
        $primaryOrder = $orders->sortByDesc('id')->first();

        $itemsByGuest = $items->groupBy('guest_id');

        $guestBreakdowns = $guests->map(function (TableSessionGuest $guest) use ($itemsByGuest) {
            $myItems = $itemsByGuest->get($guest->id, collect());
            $subtotal = 0.0;
            $unpaid = 0.0;
            $items = $myItems->map(function (OrderItem $i) use (&$subtotal, &$unpaid) {
                $sub = (float) $i->unit_price * (int) $i->quantity;
                $subtotal += $sub;
                if ($i->paid_at === null) {
                    $unpaid += $sub;
                }

                return [
                    'id' => $i->id,
                    'name' => $i->name,
                    'quantity' => (int) $i->quantity,
                    'unit_price' => (string) $i->unit_price,
                    'subtotal' => number_format($sub, 2, '.', ''),
                    'status' => $i->status,
                    'paid_at' => optional($i->paid_at)?->toIso8601String(),
                    'paid_receipt_id' => $i->paid_receipt_id,
                ];
            })->values()->all();

            return [
                'id' => $guest->id,
                'display_name' => $guest->display_name,
                'phone' => $guest->phone,
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'unpaid_amount' => number_format($unpaid, 2, '.', ''),
                'items' => $items,
            ];
        })->values();

        $paidTotal = $receipts->sum(fn ($r) => (float) $r->amount);
        $unpaidTotal = $items->where('paid_at', null)->sum(fn ($i) => (float) $i->unit_price * (int) $i->quantity);
        // Propinas: suma de todas las órdenes operativas de la sesión.
        $tipTotal = $orders->sum(fn (Order $o) => (float) $o->tip_amount);
        // Total facturable agregado: suma de `orders.total` de cada orden
        // operativa. Sirve para el header del POS y para validar `expected_total`
        // en cobros agregados.
        $aggregateTotal = $orders->sum(fn (Order $o) => (float) $o->total);

        return [
            'order' => [
                'id' => $primaryOrder->id,
                'status' => $primaryOrder->status,
                'total' => number_format($aggregateTotal, 2, '.', ''),
                'tip_amount' => number_format($tipTotal, 2, '.', ''),
            ],
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
            ],
            'orders' => $orders->values()->map(fn (Order $o) => [
                'id' => $o->id,
                'status' => $o->status,
                'total' => (string) $o->total,
                'ordered_at' => optional($o->ordered_at)?->toIso8601String(),
            ])->all(),
            'guests' => $guestBreakdowns,
            'unpaid_total' => number_format($unpaidTotal, 2, '.', ''),
            'paid_total' => number_format($paidTotal, 2, '.', ''),
            'tip_total' => number_format($tipTotal, 2, '.', ''),
            'receipts' => $receipts->map(fn (PaymentReceipt $r) => [
                'id' => $r->id,
                'guest_id' => $r->guest_id,
                'payment_method' => $r->payment_method,
                'amount' => (string) $r->amount,
                'reference' => $r->reference,
                'paid_at' => optional($r->paid_at)?->toIso8601String(),
                'client_uuid' => $r->client_uuid,
            ])->values(),
        ];
    }

    /**
     * Cobro parcial: items específicos de un comensal, paga UNO con un
     * método. Crea un PaymentReceipt con `guest_id`. Marca cada item con
     * `paid_at` + `paid_receipt_id`. Idempotente por `client_uuid`.
     *
     * @param  array{guest_id:int, item_ids:list<int>, payment_method:string, amount:string|float, reference?:?string, tip_amount?:string|float|null, client_uuid:string}  $input
     */
    public function payPartial(
        TableSession $session,
        array $input,
        User $actor,
        Request $request,
    ): PaymentReceipt {
        $this->guardPaymentMethod($input['payment_method'], allowRefund: false);

        return DB::transaction(function () use ($session, $input, $actor, $request) {
            // Idempotencia por client_uuid (unique partial index garantiza
            // no duplicar). Si ya existe, devuelve el receipt anterior.
            $existing = PaymentReceipt::withoutBranchScope()
                ->where('client_uuid', $input['client_uuid'])
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            /** @var TableSession $lockedSession */
            $lockedSession = TableSession::withoutBranchScope()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Order $order */
            $order = Order::query()
                ->withoutGlobalScopes()
                ->where('table_session_id', $lockedSession->id)
                ->lockForUpdate()
                ->firstOrFail();

            $items = OrderItem::query()
                ->whereIn('id', $input['item_ids'])
                ->where('order_id', $order->id)
                ->where('guest_id', $input['guest_id'])
                ->whereNull('paid_at')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new InvalidArgumentException('No hay items pendientes de pago en esa selección.');
            }

            $expectedAmount = round($items->sum(fn (OrderItem $i) => (float) $i->unit_price * (int) $i->quantity), 2);
            $providedAmount = round((float) $input['amount'], 2);

            // Validamos que el monto del receipt coincida con el subtotal
            // de los items elegidos. Esto blinda contra montos manipulados
            // desde el cliente — el cajero debe cobrar exactamente lo que
            // dice el sistema.
            if (abs($expectedAmount - $providedAmount) > 0.01) {
                throw new InvalidArgumentException(
                    'El monto del cobro no coincide con el subtotal de los items seleccionados.'
                );
            }

            $cashSession = $this->cashRegister->requireActiveSession($lockedSession->company_nit);

            $now = Carbon::now();

            $receipt = new PaymentReceipt;
            $receipt->order_id = $order->id;
            $receipt->guest_id = $input['guest_id'];
            $receipt->company_nit = $lockedSession->company_nit;
            $receipt->branch_id = $lockedSession->branch_id;
            $receipt->client_uuid = $input['client_uuid'];
            $receipt->payment_method = $input['payment_method'];
            $receipt->amount = number_format($expectedAmount, 2, '.', '');
            $receipt->reference = $input['reference'] ?? null;
            $receipt->paid_at = $now;
            $receipt->cash_session_id = $cashSession->id;
            $receipt->payment_data = [
                'method' => $input['payment_method'],
                'amount' => $expectedAmount,
                'guest_id' => $input['guest_id'],
                'item_ids' => $input['item_ids'],
                'tip_amount' => isset($input['tip_amount']) ? round((float) $input['tip_amount'], 2) : 0,
                'reference' => $input['reference'] ?? null,
            ];
            $receipt->save();

            foreach ($items as $item) {
                $item->paid_at = $now;
                $item->paid_receipt_id = $receipt->id;
                $item->save();
            }

            // Tip: si se entregó, suma al order.tip_amount (no a total).
            $tipDelta = isset($input['tip_amount']) ? round((float) $input['tip_amount'], 2) : 0;
            if ($tipDelta > 0) {
                $order->tip_amount = number_format(((float) $order->tip_amount) + $tipDelta, 2, '.', '');
                $order->save();
            }

            $this->audit->log(
                'table.payment.split',
                user: $actor,
                auditable: $receipt,
                data: [
                    'order_id' => $order->id,
                    'table_session_id' => $lockedSession->id,
                    'guest_id' => $input['guest_id'],
                    'amount' => $expectedAmount,
                    'tip_amount' => $tipDelta,
                    'items_paid' => $items->count(),
                    'payment_method' => $input['payment_method'],
                ],
                request: $request,
            );

            // Si todos los items están pagados, cierra la sesión y la
            // orden pasa a completed.
            $this->maybeCloseSession($order, $lockedSession);

            return $receipt;
        });
    }

    /**
     * Cobro completo: marca todos los items no pagados como cubiertos por
     * UN receipt único, cierra la sesión y libera la mesa.
     *
     * @param  array{payment_method:string, amount:string|float, reference?:?string, tip_amount?:string|float|null, client_uuid:string, payer_guest_id?:?int}  $input
     */
    public function payAll(
        TableSession $session,
        array $input,
        User $actor,
        Request $request,
    ): PaymentReceipt {
        $this->guardPaymentMethod($input['payment_method'], allowRefund: false);

        return DB::transaction(function () use ($session, $input, $actor, $request) {
            $existing = PaymentReceipt::withoutBranchScope()
                ->where('client_uuid', $input['client_uuid'])
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            /** @var TableSession $lockedSession */
            $lockedSession = TableSession::withoutBranchScope()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Una sesión puede tener N órdenes operativas (tandas aprobadas
            // del QR + órdenes que el cajero le sumó desde /orders/cashier).
            // Cobramos TODAS de una sola acción del cajero pero generamos UN
            // receipt por orden para conservar la contabilidad (un receipt
            // refiere a un order_id NOT NULL).
            $orders = Order::query()
                ->withoutGlobalScopes()
                ->where('table_session_id', $lockedSession->id)
                ->where('status', '!=', 'pending_approval')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                throw new InvalidArgumentException('Esta mesa no tiene órdenes para cobrar.');
            }

            $consumableStatuses = config('orders.item_statuses.consumable');

            $itemsByOrder = OrderItem::query()
                ->whereIn('order_id', $orders->pluck('id'))
                ->whereIn('status', $consumableStatuses)
                ->whereNull('paid_at')
                ->lockForUpdate()
                ->get()
                ->groupBy('order_id');

            $expectedAmount = round(
                $itemsByOrder->flatten()->sum(fn (OrderItem $i) => (float) $i->unit_price * (int) $i->quantity),
                2,
            );
            $providedAmount = round((float) $input['amount'], 2);

            if ($expectedAmount <= 0) {
                throw new InvalidArgumentException('No hay items pendientes de pago en esta mesa.');
            }

            if (abs($expectedAmount - $providedAmount) > 0.01) {
                throw new InvalidArgumentException(
                    'El monto del cobro no coincide con el saldo pendiente de la mesa.'
                );
            }

            $cashSession = $this->cashRegister->requireActiveSession($lockedSession->company_nit);

            $now = Carbon::now();
            $tipDelta = isset($input['tip_amount']) ? round((float) $input['tip_amount'], 2) : 0;

            // Distribución de propina proporcional al total de cada orden.
            // Si la sesión tiene una sola orden, toda la propina va ahí; si
            // tiene varias, se reparte ponderada por monto cobrado.
            $primaryReceipt = null;
            $receiptsCreated = [];

            foreach ($orders as $idx => $order) {
                $orderItems = $itemsByOrder->get($order->id, collect());
                if ($orderItems->isEmpty()) {
                    continue;
                }

                $orderAmount = round(
                    $orderItems->sum(fn (OrderItem $i) => (float) $i->unit_price * (int) $i->quantity),
                    2,
                );

                // client_uuid por orden: UUID v5 determinístico derivado del
                // client_uuid base + order->id. Antes se concatenaba
                // `base.'-'.order->id`, pero order->id es UUID → el resultado
                // era dos UUIDs pegados, inválido para la columna `uuid` de
                // payment_receipts (SQLSTATE 22P02). uuid5 es válido y
                // determinístico, así que conserva idempotencia ante reintentos.
                $orderClientUuid = (string) Uuid::uuid5(Uuid::NAMESPACE_OID, $input['client_uuid'].':'.$order->id);

                // Idempotencia por orden: si el receipt de esta orden ya existe
                // (reintento del cajero con el mismo client_uuid base), lo
                // reutilizamos sin re-cobrar ni re-tocar items. El guard
                // superior valida el client_uuid base, que nunca se persiste en
                // pay-all (cada receipt usa el derivado), así que la idempotencia
                // real vive acá.
                $existingForOrder = PaymentReceipt::withoutBranchScope()
                    ->where('client_uuid', $orderClientUuid)
                    ->first();
                if ($existingForOrder !== null) {
                    if ($idx === 0) {
                        $primaryReceipt = $existingForOrder;
                    }
                    $receiptsCreated[] = $existingForOrder;

                    continue;
                }

                $orderTipShare = $expectedAmount > 0
                    ? round($tipDelta * ($orderAmount / $expectedAmount), 2)
                    : 0;

                $receipt = new PaymentReceipt;
                $receipt->order_id = $order->id;
                $receipt->guest_id = $input['payer_guest_id'] ?? null;
                $receipt->company_nit = $lockedSession->company_nit;
                $receipt->branch_id = $lockedSession->branch_id;
                $receipt->client_uuid = $orderClientUuid;
                $receipt->payment_method = $input['payment_method'];
                $receipt->amount = number_format($orderAmount, 2, '.', '');
                $receipt->reference = $input['reference'] ?? null;
                $receipt->paid_at = $now;
                $receipt->cash_session_id = $cashSession->id;
                $receipt->payment_data = [
                    'method' => $input['payment_method'],
                    'amount' => $orderAmount,
                    'payer_guest_id' => $input['payer_guest_id'] ?? null,
                    'tip_amount' => $orderTipShare,
                    'reference' => $input['reference'] ?? null,
                    'item_ids' => $orderItems->pluck('id')->all(),
                    'aggregate_amount' => $expectedAmount,
                    'aggregate_client_uuid' => $input['client_uuid'],
                ];
                $receipt->save();

                foreach ($orderItems as $item) {
                    $item->paid_at = $now;
                    $item->paid_receipt_id = $receipt->id;
                    $item->save();
                }

                if ($orderTipShare > 0) {
                    $order->tip_amount = number_format(((float) $order->tip_amount) + $orderTipShare, 2, '.', '');
                    $order->save();
                }

                $this->audit->log(
                    'table.payment.full',
                    user: $actor,
                    auditable: $receipt,
                    data: [
                        'order_id' => $order->id,
                        'table_session_id' => $lockedSession->id,
                        'payer_guest_id' => $input['payer_guest_id'] ?? null,
                        'amount' => $orderAmount,
                        'aggregate_amount' => $expectedAmount,
                        'tip_amount' => $orderTipShare,
                        'items_paid' => $orderItems->count(),
                        'payment_method' => $input['payment_method'],
                    ],
                    request: $request,
                );

                $this->maybeCloseSession($order->refresh(), $lockedSession);

                if ($idx === 0) {
                    $primaryReceipt = $receipt;
                }
                $receiptsCreated[] = $receipt;
            }

            // Devolvemos el primer receipt para conservar la firma actual del
            // endpoint. La UI puede listar todos mirando `state.receipts`.
            return $primaryReceipt ?? $receiptsCreated[0];
        });
    }

    /**
     * Refund parcial de un item ya pagado. Crea un receipt con `amount`
     * negativo y mismo `guest_id`. El item pasa a `cancelled` con
     * `cancellation_reason='refunded'`.
     *
     * @param  array{item_id:int, payment_method:string, amount:string|float, reference:string, client_uuid:string}  $input
     */
    public function refundItem(
        TableSession $session,
        array $input,
        User $actor,
        Request $request,
    ): PaymentReceipt {
        if ($input['payment_method'] === 'refund') {
            throw new InvalidArgumentException('Indica el método original (cash/card/transfer), no "refund".');
        }
        $this->guardPaymentMethod($input['payment_method'], allowRefund: false);
        if (trim($input['reference']) === '') {
            throw new InvalidArgumentException('Es obligatorio registrar la referencia del refund (comprobante de devolución).');
        }

        return DB::transaction(function () use ($session, $input, $actor, $request) {
            $existing = PaymentReceipt::withoutBranchScope()
                ->where('client_uuid', $input['client_uuid'])
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            /** @var TableSession $lockedSession */
            $lockedSession = TableSession::withoutBranchScope()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var OrderItem $item */
            $item = OrderItem::query()->whereKey($input['item_id'])->lockForUpdate()->firstOrFail();

            if ($item->paid_at === null) {
                throw new InvalidArgumentException('No se puede devolver un item que aún no ha sido pagado.');
            }

            /** @var Order $order */
            $order = Order::query()->withoutGlobalScopes()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();

            if ($order->table_session_id !== $lockedSession->id) {
                throw new InvalidArgumentException('Ese item no pertenece a esta sesión.');
            }

            $expectedAmount = round((float) $item->unit_price * (int) $item->quantity, 2);
            $providedAmount = round((float) $input['amount'], 2);

            if (abs($expectedAmount - $providedAmount) > 0.01) {
                throw new InvalidArgumentException(
                    'El monto del refund no coincide con el subtotal del item.'
                );
            }

            $cashSession = $this->cashRegister->requireActiveSession($lockedSession->company_nit);

            $negativeAmount = -1 * $expectedAmount;

            $receipt = new PaymentReceipt;
            $receipt->order_id = $order->id;
            $receipt->guest_id = $item->guest_id;
            $receipt->company_nit = $lockedSession->company_nit;
            $receipt->branch_id = $lockedSession->branch_id;
            $receipt->client_uuid = $input['client_uuid'];
            $receipt->payment_method = 'refund';
            $receipt->amount = number_format($negativeAmount, 2, '.', '');
            $receipt->reference = $input['reference'];
            $receipt->paid_at = Carbon::now();
            $receipt->cash_session_id = $cashSession->id;
            $receipt->payment_data = [
                'method' => 'refund',
                'original_method' => $input['payment_method'],
                'amount' => $negativeAmount,
                'item_id' => $item->id,
                'guest_id' => $item->guest_id,
                'reference' => $input['reference'],
            ];
            $receipt->save();

            // Marcar item cancelled con reason=refunded.
            $item->status = 'cancelled';
            $item->cancellation_reason = 'refunded';
            $item->cancelled_at = Carbon::now();
            $item->save();

            $this->totals->recalculateAndSave($order);

            $this->audit->log(
                'table.payment.refunded',
                user: $actor,
                auditable: $receipt,
                data: [
                    'order_id' => $order->id,
                    'table_session_id' => $lockedSession->id,
                    'item_id' => $item->id,
                    'guest_id' => $item->guest_id,
                    'original_method' => $input['payment_method'],
                    'amount' => $negativeAmount,
                    'reference' => $input['reference'],
                ],
                request: $request,
            );

            return $receipt;
        });
    }

    /**
     * Si ya no quedan items pendientes de pago, cierra la sesión y
     * promueve la orden a `completed`.
     */
    private function maybeCloseSession(Order $order, TableSession $session): void
    {
        // Si la orden recién cobrada todavía tiene items consumibles sin pagar,
        // no podemos completarla.
        $unpaidExists = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereIn('status', config('orders.item_statuses.consumable'))
            ->whereNull('paid_at')
            ->exists();

        if ($unpaidExists) {
            return;
        }

        $order->status = 'completed';
        $order->save();

        // La sesión se cierra solo cuando TODAS sus órdenes operativas están
        // pagadas (cada una `completed` / terminal). Una sesión puede tener
        // N órdenes (tandas aprobadas + órdenes del cajero); no podemos
        // cerrarla mientras alguna siga abierta.
        $terminalStatuses = array_merge(
            config('orders.revenue', ['completed']),
            config('orders.terminal_failure', ['cancelled', 'refunded', 'failed', 'abandoned']),
        );

        $remainingOpen = Order::query()
            ->withoutGlobalScopes()
            ->where('table_session_id', $session->id)
            ->where('status', '!=', 'pending_approval')
            ->whereNotIn('status', $terminalStatuses)
            ->exists();

        if ($remainingOpen) {
            return;
        }

        $this->sessions->closeSession($session);
    }

    /**
     * Solo cash/card/transfer son cobros válidos. `refund` se reserva
     * para `refundItem()` que setea el método directamente.
     */
    private function guardPaymentMethod(string $method, bool $allowRefund): void
    {
        $allowed = $allowRefund ? ['cash', 'card', 'transfer', 'refund'] : ['cash', 'card', 'transfer'];
        if (! in_array($method, $allowed, true)) {
            throw new InvalidArgumentException('Método de pago inválido.');
        }
    }
}
