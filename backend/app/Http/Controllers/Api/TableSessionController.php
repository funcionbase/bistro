<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CancellationRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\TableSession;
use App\Models\TableSessionGuest;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\TableWaiterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * API del mesero para sesiones de mesa con QR.
 *
 * Requiere JWT + sede activa + permiso `orders.update`/`orders.read`.
 * Devuelve JSON. La UI vive en `pages/orders/table-sessions/*`.
 */
class TableSessionController extends Controller
{
    public function __construct(private readonly TableWaiterService $waiter) {}

    /**
     * Lista las sesiones activas de la sede actual, con count de items
     * pending_approval para que el mesero sepa qué mesa tiene tandas
     * esperando aprobación.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        $sessions = TableSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereIn('status', config('tables.active_statuses'))
            ->with([
                'table:id,number,capacity',
                'guests:id,table_session_id,display_name,phone',
                'order:id,table_session_id,status,total',
            ])
            ->orderBy('opened_at')
            ->get()
            ->map(function (TableSession $s) {
                $orderId = optional($s->order)->id;
                $pending = $orderId
                    ? OrderItem::query()
                        ->where('order_id', $orderId)
                        ->where('status', 'pending_approval')
                        ->whereNotNull('submitted_at')
                        ->count()
                    : 0;

                $cancellationsOpen = $orderId
                    ? CancellationRequest::query()
                        ->whereIn('order_item_id', OrderItem::query()->where('order_id', $orderId)->pluck('id'))
                        ->where('status', 'pending')
                        ->count()
                    : 0;

                return [
                    'id' => $s->id,
                    'status' => $s->status,
                    'opened_at' => optional($s->opened_at)?->toIso8601String(),
                    'expires_at' => optional($s->expires_at)?->toIso8601String(),
                    'accepts_new_guests' => (bool) $s->accepts_new_guests,
                    'table' => [
                        'id' => $s->table?->id,
                        'number' => $s->table?->number,
                        'capacity' => $s->table?->capacity,
                    ],
                    'guests_count' => $s->guests->count(),
                    'order' => $s->order ? [
                        'id' => $s->order->id,
                        'status' => $s->order->status,
                        'total' => (string) $s->order->total,
                    ] : null,
                    'pending_approval_count' => $pending,
                    'cancellation_requests_open' => $cancellationsOpen,
                ];
            });

        return response()->json(['data' => $sessions]);
    }

    /**
     * Detalle de una sesión: comensales, items agrupados por (guest_id,
     * submitted_at minuto), notas, cancellation_requests pendientes.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        /** @var TableSession $session */
        $session = TableSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->with(['table', 'guests', 'orders'])
            ->findOrFail($id);

        // Una sesión ahora puede tener N órdenes (una por tanda aprobada) +
        // la buffer (status=pending_approval). Traemos TODAS y agrupamos.
        $orders = $session->orders->sortBy('id')->values();
        $bufferOrder = $orders->firstWhere('status', 'pending_approval');
        $approvedOrders = $orders->filter(fn (Order $o) => $o->status !== 'pending_approval')->values();

        $allOrderIds = $orders->pluck('id')->all();

        $items = empty($allOrderIds)
            ? collect()
            : OrderItem::query()->whereIn('order_id', $allOrderIds)->orderBy('id')->get();

        $notes = empty($allOrderIds)
            ? collect()
            : OrderNote::query()->whereIn('order_id', $allOrderIds)->orderBy('id')->get();

        $cancellations = CancellationRequest::query()
            ->whereIn('order_item_id', $items->pluck('id'))
            ->orderBy('id')
            ->get();

        $guestsById = $session->guests->keyBy('id');

        // Items en la buffer alimentan los `pending_batches` (lo que espera
        // aprobación). Items en órdenes aprobadas se exponen agrupados por
        // `order_id` en `approved_orders` para que la UI muestre cada tanda
        // como una sección separada con su número de orden.
        $bufferItems = $bufferOrder
            ? $items->where('order_id', $bufferOrder->id)
            : collect();
        $batches = $this->groupItemsByBatch($bufferItems, $guestsById);

        $approvedOrdersPayload = $approvedOrders->map(function (Order $o) use ($items, $guestsById) {
            $orderItems = $items->where('order_id', $o->id)->values();

            return [
                'id' => $o->id,
                'status' => $o->status,
                'total' => (string) $o->total,
                'ordered_at' => optional($o->ordered_at)?->toIso8601String(),
                'items' => $orderItems->map(fn (OrderItem $i) => [
                    'id' => $i->id,
                    'menu_item_id' => $i->menu_item_id,
                    'name' => $i->name,
                    'unit_price' => (string) $i->unit_price,
                    'quantity' => (int) $i->quantity,
                    'notes' => $i->notes,
                    'status' => $i->status,
                    'cancellation_reason' => $i->cancellation_reason,
                    'guest_id' => $i->guest_id,
                    'guest_label' => $i->guest_id
                        ? optional($guestsById->get((int) $i->guest_id))->display_name
                        : null,
                    'approved_at' => optional($i->approved_at)?->toIso8601String(),
                    'in_kitchen_at' => optional($i->in_kitchen_at)?->toIso8601String(),
                    'ready_at' => optional($i->ready_at)?->toIso8601String(),
                    'served_at' => optional($i->served_at)?->toIso8601String(),
                ])->values()->all(),
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'id' => $session->id,
                'status' => $session->status,
                'accepts_new_guests' => (bool) $session->accepts_new_guests,
                'opened_at' => optional($session->opened_at)?->toIso8601String(),
                'expires_at' => optional($session->expires_at)?->toIso8601String(),
                'table' => [
                    'id' => $session->table?->id,
                    'number' => $session->table?->number,
                    'capacity' => $session->table?->capacity,
                ],
                // `order` queda como la buffer (compat: el UI legacy puede leer
                // total tentativo de items por aprobar). El nuevo render
                // grupal usa `approved_orders`.
                'order' => $bufferOrder ? [
                    'id' => $bufferOrder->id,
                    'status' => $bufferOrder->status,
                    'total' => (string) $bufferOrder->total,
                ] : null,
                'approved_orders' => $approvedOrdersPayload,
                'guests' => $session->guests->map(fn ($g) => [
                    'id' => $g->id,
                    'display_name' => $g->display_name,
                    'phone' => $g->phone,
                    'joined_at' => optional($g->joined_at)?->toIso8601String(),
                ])->values(),
                'pending_batches' => $batches['pending'],
                'items_by_status' => $batches['by_status'],
                'group_notes' => $notes->map(fn ($n) => [
                    'id' => $n->id,
                    'scope' => $n->scope,
                    'body' => $n->body,
                    'author_type' => $n->author_type,
                    'author_id' => $n->author_id,
                    'created_at' => optional($n->created_at)?->toIso8601String(),
                ])->values(),
                'cancellation_requests' => $cancellations->map(fn ($cr) => [
                    'id' => $cr->id,
                    'order_item_id' => $cr->order_item_id,
                    'guest_id' => $cr->guest_id,
                    'status' => $cr->status,
                    'reason' => $cr->reason,
                    'resolved_at' => optional($cr->resolved_at)?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Lista resumida de items `pending_approval` con `submitted_at` ya
     * seteado (es decir, ya enviados al mesero por el comensal y esperando
     * aprobación), agrupados por mesa.
     *
     * Usado por el banner sticky en /orders/tables y /orders/cashier para
     * avisar que hay tandas esperando — el usuario hace click y navega al
     * detalle de la sesión donde aprueba o rechaza.
     *
     * Filtra estrictamente por `active_company_nit` + `active_branch_id`
     * (sin soporte de `?branch=all`): una alerta operativa siempre vive en
     * la sede actual; cruzar sedes generaría ruido para meseros/cajeros que
     * no operan esas mesas.
     */
    public function pendingApprovals(Request $request): JsonResponse
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        $sessions = TableSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereIn('status', config('tables.active_statuses'))
            ->with([
                'table:id,number',
                'guests:id,table_session_id,display_name',
                'order:id,table_session_id',
            ])
            ->get();

        $data = [];

        foreach ($sessions as $session) {
            $orderId = optional($session->order)->id;
            if ($orderId === null) {
                continue;
            }

            $items = OrderItem::query()
                ->where('order_id', $orderId)
                ->where('status', 'pending_approval')
                ->whereNotNull('submitted_at')
                ->orderBy('submitted_at')
                ->get(['id', 'name', 'quantity', 'notes', 'guest_id', 'submitted_at']);

            if ($items->isEmpty()) {
                continue;
            }

            $guestsById = $session->guests->keyBy('id');

            $data[] = [
                'session_id' => $session->id,
                'table' => [
                    'id' => $session->table?->id,
                    'number' => $session->table?->number,
                ],
                'oldest_submitted_at' => optional($items->first()->submitted_at)?->toIso8601String(),
                'items_count' => $items->count(),
                'items' => $items->map(fn (OrderItem $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'quantity' => (int) $i->quantity,
                    'notes' => $i->notes,
                    'guest_name' => optional($guestsById->get($i->guest_id))->display_name ?? 'Comensal',
                    'submitted_at' => optional($i->submitted_at)?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        usort($data, fn ($a, $b) => strcmp((string) $a['oldest_submitted_at'], (string) $b['oldest_submitted_at']));

        return response()->json(['data' => $data]);
    }

    /**
     * Solicitudes de cancelación pendientes — clientes pidieron cancelar
     * items ya aprobados. Agrupado por sesión para que el frontend muestre
     * "Mesa N · 2 solicitudes" y el mesero/cajero entren a resolver.
     *
     * Misma política de scope que `pendingApprovals`: estricto por sede.
     */
    public function pendingCancellations(Request $request): JsonResponse
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        // Resolvemos sesiones activas de la sede, ordenes vinculadas, e items.
        $sessions = TableSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereIn('status', config('tables.active_statuses'))
            ->with(['table:id,number', 'guests:id,table_session_id,display_name', 'order:id,table_session_id'])
            ->get();

        $data = [];

        foreach ($sessions as $session) {
            $orderId = optional($session->order)->id;
            if ($orderId === null) {
                continue;
            }

            $requests = CancellationRequest::query()
                ->where('status', 'pending')
                ->whereIn('order_item_id', function ($q) use ($orderId) {
                    $q->select('id')->from('order_items')->where('order_id', $orderId);
                })
                ->with(['item:id,name,quantity'])
                ->orderBy('created_at')
                ->get();

            if ($requests->isEmpty()) {
                continue;
            }

            $guestsById = $session->guests->keyBy('id');

            $data[] = [
                'session_id' => $session->id,
                'table' => [
                    'id' => $session->table?->id,
                    'number' => $session->table?->number,
                ],
                'oldest_requested_at' => optional($requests->first()->created_at)?->toIso8601String(),
                'requests_count' => $requests->count(),
                'requests' => $requests->map(fn (CancellationRequest $r) => [
                    'id' => $r->id,
                    'item_name' => optional($r->item)->name ?? 'Plato',
                    'quantity' => (int) (optional($r->item)->quantity ?? 1),
                    'reason' => $r->reason,
                    'guest_name' => optional($guestsById->get($r->guest_id))->display_name ?? 'Comensal',
                    'requested_at' => optional($r->created_at)?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        usort($data, fn ($a, $b) => strcmp((string) $a['oldest_requested_at'], (string) $b['oldest_requested_at']));

        return response()->json(['data' => $data]);
    }

    /**
     * Lista mesas con órdenes pendientes de cobro. Una sesión es "facturable"
     * cuando tiene al menos una orden en estado operativo (no completed /
     * cancelled / refunded / abandoned). El total a cobrar suma esas órdenes
     * pendientes — la buffer (pending_approval) NO suma porque aún no se
     * aprobó la cocina.
     *
     * Usado por /orders/cashier para que el cajero vea qué mesas le tocan
     * cobrar. Filtra estrictamente por sede activa.
     */
    public function billable(Request $request): JsonResponse
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        $terminal = array_merge(
            config('orders.revenue', ['completed']),
            config('orders.terminal_failure', ['cancelled', 'refunded', 'failed', 'abandoned']),
        );

        $sessions = TableSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereIn('status', config('tables.active_statuses'))
            ->with(['table:id,number', 'guests:id,table_session_id,display_name', 'orders'])
            ->get();

        $data = [];

        foreach ($sessions as $session) {
            $billableOrders = $session->orders->filter(
                fn (Order $o) => $o->status !== 'pending_approval' && ! in_array($o->status, $terminal, true),
            );

            if ($billableOrders->isEmpty()) {
                continue;
            }

            $totalDue = $billableOrders->sum(fn (Order $o) => (float) $o->total);

            $data[] = [
                'session_id' => $session->id,
                'table' => [
                    'id' => $session->table?->id,
                    'number' => $session->table?->number,
                ],
                'guests_count' => $session->guests->count(),
                'guests_preview' => $session->guests->take(3)->pluck('display_name')->all(),
                'opened_at' => optional($session->opened_at)?->toIso8601String(),
                'orders_count' => $billableOrders->count(),
                'total_due' => round($totalDue, 2),
                'orders' => $billableOrders->values()->map(fn (Order $o) => [
                    'id' => $o->id,
                    'status' => $o->status,
                    'total' => (float) $o->total,
                    'ordered_at' => optional($o->ordered_at)?->toIso8601String(),
                ])->all(),
            ];
        }

        // Mesas más viejas (más esperan) primero.
        usort($data, fn ($a, $b) => strcmp((string) $a['opened_at'], (string) $b['opened_at']));

        return response()->json(['data' => $data]);
    }

    /**
     * @deprecated Reemplazado por `TableCashierController::payAll` que ya
     * existía y delega en `TableCashierService::payAll`. Mantenemos el método
     * solo por compatibilidad temporal con la ruta /charge-all si quedó
     * referenciada en algún lugar.
     */
    public function chargeAll(Request $request, string $id): JsonResponse
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'payment_method' => ['required', 'string', Rule::in(['cash', 'card', 'transfer'])],
            'reference' => ['nullable', 'string', 'max:64'],
            'amount_received' => ['nullable', 'numeric', 'min:0'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var TableSession $session */
        $session = TableSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->findOrFail($id);

        $terminal = array_merge(
            config('orders.revenue', ['completed']),
            config('orders.terminal_failure', ['cancelled', 'refunded', 'failed', 'abandoned']),
        );

        $orders = Order::withoutGlobalScopes()
            ->where('table_session_id', $session->id)
            ->where('status', '!=', 'pending_approval')
            ->whereNotIn('status', $terminal)
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'message' => 'Esta mesa no tiene órdenes pendientes de cobro.',
            ], 422);
        }

        $charged = [];

        DB::transaction(function () use ($orders, $validated, $request, &$charged): void {
            $orderController = app(OrderController::class);
            $expectedTotal = $orders->sum(fn (Order $o) => (float) $o->total);
            $amountReceived = (float) ($validated['amount_received'] ?? $expectedTotal);
            $tip = (float) ($validated['tip_amount'] ?? 0);

            foreach ($orders as $order) {
                // Sub-request por orden con su monto. Reutiliza
                // OrderController::closeWithPayment para conservar la
                // contabilidad y los hooks (loyalty, audit, etc.).
                $sub = Request::create(
                    uri: "/api/v1/orders/{$order->id}/close-with-payment",
                    method: 'POST',
                    parameters: [
                        'payment_method' => $validated['payment_method'],
                        'expected_total' => (float) $order->total,
                        'amount_received' => $amountReceived * ((float) $order->total / max($expectedTotal, 0.01)),
                        'reference' => $validated['reference'] ?? null,
                        'tip_amount' => $tip * ((float) $order->total / max($expectedTotal, 0.01)),
                    ],
                );
                $sub->attributes->add($request->attributes->all());
                $sub->headers->add($request->headers->all());

                // closeWithPayment(string $id) — orders.id es uuid. (int)
                // devolvía PHP_INT_MAX y la lookup nunca matcheaba la orden.
                $response = $orderController->closeWithPayment($sub, (string) $order->id);
                $body = json_decode($response->getContent(), true);
                $charged[] = [
                    'order_id' => $order->id,
                    'status' => $response->getStatusCode(),
                    'total' => (float) $order->total,
                    'receipt_id' => $body['data']['payment_receipt_id'] ?? null,
                ];
            }
        });

        // Audit del cobro consolidado (cada orden individual ya tiene su propio
        // audit log en closeWithPayment; este es el rastro del agregado).
        app(AuditService::class)->log(
            'table.session.charged_all',
            user: $request->user() ?? User::find($request->attributes->get('jwt_payload')['sub'] ?? null),
            auditable: $session,
            data: [
                'orders_charged' => count($charged),
                'orders' => $charged,
                'payment_method' => $validated['payment_method'],
            ],
            request: $request,
        );

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'orders_charged' => count($charged),
                'orders' => $charged,
            ],
        ]);
    }

    public function approveBatch(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
        ]);

        try {
            $result = $this->waiter->approveBatch($session, $payload['item_ids'], $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'approved' => $result['approved'],
            'session' => [
                'id' => $result['session']->id,
                'status' => $result['session']->status,
                'accepts_new_guests' => (bool) $result['session']->accepts_new_guests,
            ],
            'order' => [
                'id' => $result['order']->id,
                'status' => $result['order']->status,
                'total' => (string) $result['order']->total,
            ],
        ]);
    }

    public function rejectItem(Request $request, string $id, string $itemId): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'reason' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ]);

        $item = OrderItem::query()->whereKey($itemId)->firstOrFail();

        try {
            $updated = $this->waiter->rejectItem($item, $session, $payload['reason'] ?? null, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'item' => [
                'id' => $updated->id,
                'status' => $updated->status,
                'cancellation_reason' => $updated->cancellation_reason,
            ],
        ]);
    }

    public function cancelItem(Request $request, string $id, string $itemId): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'reason' => ['required', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ]);

        $item = OrderItem::query()->whereKey($itemId)->firstOrFail();

        try {
            $updated = $this->waiter->cancelItemInKitchen($item, $session, $payload['reason'], $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'item' => [
                'id' => $updated->id,
                'status' => $updated->status,
                'cancellation_reason' => $updated->cancellation_reason,
            ],
        ]);
    }

    public function editItemNotes(Request $request, string $id, string $itemId): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'notes' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ]);

        $item = OrderItem::query()->whereKey($itemId)->firstOrFail();
        $updated = $this->waiter->editItemNotes($item, $session, $payload['notes'] ?? null, $user, $request);

        return response()->json([
            'item' => [
                'id' => $updated->id,
                'notes' => $updated->notes,
            ],
        ]);
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'scope' => ['required', 'string', 'in:group,kitchen_alert'],
            'body' => ['required', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ]);

        /** @var Order $order */
        $order = Order::query()
            ->withoutGlobalScopes()
            ->where('table_session_id', $session->id)
            ->firstOrFail();

        try {
            $note = $this->waiter->addNote($order, $payload['scope'], $payload['body'], $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'note' => [
                'id' => $note->id,
                'scope' => $note->scope,
                'body' => $note->body,
            ],
        ], 201);
    }

    public function closeEmpty(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        try {
            $closed = $this->waiter->closeEmpty($session, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'session' => [
                'id' => $closed->id,
                'status' => $closed->status,
                'closed_at' => optional($closed->closed_at)?->toIso8601String(),
            ],
        ]);
    }

    public function toggleAcceptsNewGuests(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'accepts' => ['required', 'boolean'],
        ]);

        $updated = $this->waiter->toggleAcceptsNewGuests($session, (bool) $payload['accepts'], $user, $request);

        return response()->json([
            'session' => [
                'id' => $updated->id,
                'status' => $updated->status,
                'accepts_new_guests' => (bool) $updated->accepts_new_guests,
            ],
        ]);
    }

    /**
     * Carga la sesión asegurando que pertenece a la sede activa del actor.
     */
    private function loadSession(Request $request, string $id): TableSession
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        return TableSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        $sub = $request->attributes->get('jwt_payload')['sub'] ?? null;

        return User::query()->findOrFail($sub);
    }

    /**
     * Agrupa los items de la orden en "tandas" pendientes de aprobación y en
     * cubos por estado.
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  Collection<int, TableSessionGuest>  $guestsById
     * @return array{pending: list<array{guest_id: int, guest_name: string, submitted_at: ?string, items: list<array<string, mixed>>}>, by_status: array<string, list<array<string, mixed>>>}
     */
    private function groupItemsByBatch($items, $guestsById): array
    {
        $pending = [];
        $byStatus = [
            'pending_approval' => [],
            'approved' => [],
            'in_kitchen' => [],
            'ready' => [],
            'served' => [],
            'cancelled' => [],
        ];

        $batchMap = [];

        foreach ($items as $item) {
            $serialized = [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'name' => $item->name,
                'unit_price' => (string) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'notes' => $item->notes,
                'status' => $item->status,
                'cancellation_reason' => $item->cancellation_reason,
                'guest_id' => $item->guest_id,
                'submitted_at' => optional($item->submitted_at)?->toIso8601String(),
                'approved_at' => optional($item->approved_at)?->toIso8601String(),
                'in_kitchen_at' => optional($item->in_kitchen_at)?->toIso8601String(),
                'ready_at' => optional($item->ready_at)?->toIso8601String(),
                'served_at' => optional($item->served_at)?->toIso8601String(),
            ];

            $byStatus[$item->status][] = $serialized;

            if ($item->status === 'pending_approval' && $item->submitted_at !== null) {
                $bucket = ($item->guest_id ?? 0).'|'.$item->submitted_at->format('Y-m-d H:i');
                if (! isset($batchMap[$bucket])) {
                    $guest = $guestsById->get($item->guest_id);
                    $batchMap[$bucket] = [
                        'guest_id' => (int) $item->guest_id,
                        'guest_name' => $guest?->display_name ?? 'Comensal',
                        'submitted_at' => optional($item->submitted_at)?->toIso8601String(),
                        'items' => [],
                    ];
                }
                $batchMap[$bucket]['items'][] = $serialized;
            }
        }

        foreach ($batchMap as $bucket) {
            $pending[] = $bucket;
        }

        return ['pending' => $pending, 'by_status' => $byStatus];
    }
}
