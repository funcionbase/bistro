<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\OrderItemSubmittedForApproval;
use App\Models\CancellationRequest;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\RestaurantMenu;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TableSessionGuest;
use App\Support\OrderTotalCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mutaciones del carrito del comensal dentro del flujo de mesa con QR (#191).
 *
 * Reglas contables (CLAUDE.md):
 *  - `unit_price` siempre desde `RestaurantMenu::findMenuItem` — NUNCA del payload.
 *  - Toda mutación: `DB::transaction` + `Order::lockForUpdate`.
 *  - `orders.total` se recalcula via `OrderTotalCalculator` tras cada cambio.
 *  - Cada acción registra `AuditService::log` con metadata accionable.
 *
 * Ciclo de vida del item visto desde el cliente:
 *  - pending_approval: agregado, editable, cancelable inmediato.
 *  - approved (post mesero): NO editable. Cancelar crea `CancellationRequest`.
 *  - in_kitchen | ready | served: el cliente NO puede cancelar; debe hablar
 *    con el mesero. La API responde 422 explicando el camino correcto.
 */
class TableOrderService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OrderTotalCalculator $totals,
        private readonly TaxCalculator $taxes,
    ) {}

    /**
     * Agrega un item al carrito del comensal. Crea la orden de la sesión
     * lazy si aún no existe. Lee precio del menú activo de la empresa.
     */
    public function addItem(
        TableSessionGuest $guest,
        string $menuItemId,
        int $quantity,
        ?string $notes,
        Request $request,
    ): OrderItem {
        if ($quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('La cantidad debe estar entre 1 y 99.');
        }

        $notes = $this->sanitizeNotes($notes);

        return DB::transaction(function () use ($guest, $menuItemId, $quantity, $notes, $request) {
            /** @var TableSession $session */
            $session = $guest->session()->lockForUpdate()->firstOrFail();
            $this->guardSessionAllowsChanges($session);

            $menuItem = $this->resolveMenuItem($session->company_nit, $session->branch_id, $menuItemId);

            $order = $this->resolveOrderForSession($session);

            $item = new OrderItem;
            $item->order_id = $order->id;
            $item->menu_item_id = (string) ($menuItem['id'] ?? $menuItemId);
            $item->guest_id = $guest->id;
            $item->name = (string) ($menuItem['name'] ?? 'Item');
            $item->unit_price = (string) number_format((float) ($menuItem['price'] ?? 0), 2, '.', '');
            $item->unit_cost = isset($menuItem['cost']) ? (string) number_format((float) $menuItem['cost'], 2, '.', '') : null;
            // Snapshot de la tasa efectiva por línea (item del menú > default
            // congelado en la orden), paridad con buildOrderLines de caja (#293).
            $item->tax_rate = $this->taxes->resolveRate(
                isset($menuItem['tax_rate']) ? (float) $menuItem['tax_rate'] : null,
                (float) ($order->snapshot_default_tax_rate ?? 0),
            );
            $item->quantity = $quantity;
            $item->category = isset($menuItem['category']) ? (string) $menuItem['category'] : null;
            $item->notes = $notes;
            $item->status = 'pending_approval';
            $item->submitted_at = Carbon::now();
            $item->save();

            $this->totals->recalculateAndSave($order->refresh());
            $this->bumpExpiry($session);

            $this->audit->log(
                'table.item.added_by_customer',
                user: null,
                auditable: $item,
                data: [
                    'order_id' => $order->id,
                    'table_session_id' => $session->id,
                    'guest_id' => $guest->id,
                    'menu_item_id' => $item->menu_item_id,
                    'unit_price' => $item->unit_price,
                    'quantity' => $item->quantity,
                ],
                request: $request,
            );

            // Dispara push notification a usuarios con orders.update en
            // la sede de la orden. El listener (queued) NotifyPendingApprovalListener
            // encola SendPendingApprovalPushJob, así el flujo HTTP del comensal
            // no paga la latencia del envío. Event::dispatch dentro de la
            // transacción es seguro porque los listeners queued sólo
            // serializan al disparar; el job se materializa cuando la
            // transacción commitea.
            event(new OrderItemSubmittedForApproval($item));

            return $item;
        });
    }

    /**
     * Edita `notes` y/o `quantity` de un item del comensal. Solo permitido
     * mientras `status=pending_approval` — después es campo del mesero/cocina.
     *
     * @param  array{notes?: ?string, quantity?: int}  $changes
     */
    public function updateItem(
        OrderItem $item,
        TableSessionGuest $guest,
        array $changes,
        Request $request,
    ): OrderItem {
        return DB::transaction(function () use ($item, $guest, $changes, $request) {
            /** @var OrderItem $locked */
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->guest_id !== $guest->id) {
                throw new InvalidArgumentException('Este item no es tuyo.');
            }

            if ($locked->status !== 'pending_approval') {
                throw new InvalidArgumentException('Ya no podés editar este item — pedile al mesero.');
            }

            $dirty = [];

            if (array_key_exists('notes', $changes)) {
                $newNotes = $this->sanitizeNotes($changes['notes']);
                if ($newNotes !== $locked->notes) {
                    $locked->notes = $newNotes;
                    $dirty[] = 'notes';
                }
            }

            if (array_key_exists('quantity', $changes) && $changes['quantity'] !== null) {
                $quantity = (int) $changes['quantity'];
                if ($quantity < 1 || $quantity > 99) {
                    throw new InvalidArgumentException('La cantidad debe estar entre 1 y 99.');
                }
                if ($quantity !== (int) $locked->quantity) {
                    $locked->quantity = $quantity;
                    $dirty[] = 'quantity';
                }
            }

            if ($dirty === []) {
                return $locked;
            }

            $locked->save();

            /** @var Order $order */
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $this->totals->recalculateAndSave($order);

            $this->audit->log(
                'table.item.edited_by_customer',
                user: null,
                auditable: $locked,
                data: [
                    'order_id' => $locked->order_id,
                    'guest_id' => $guest->id,
                    'dirty_fields' => $dirty,
                ],
                request: $request,
            );

            return $locked;
        });
    }

    /**
     * Cancela un item según su estado actual:
     *  - pending_approval: cancela inmediato (status=cancelled, reason=customer).
     *  - approved: crea CancellationRequest pendiente para el mesero.
     *  - in_kitchen+: 422 con mensaje explicando que el mesero debe hacerlo.
     *
     * @return array{kind: 'cancelled'|'request_created', item: OrderItem, request: CancellationRequest|null}
     */
    public function cancelItem(
        OrderItem $item,
        TableSessionGuest $guest,
        ?string $reason,
        Request $request,
    ): array {
        $reason = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;

        return DB::transaction(function () use ($item, $guest, $reason, $request) {
            /** @var OrderItem $locked */
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->guest_id !== $guest->id) {
                throw new InvalidArgumentException('Este item no es tuyo.');
            }

            if ($locked->status === 'pending_approval') {
                $locked->status = 'cancelled';
                $locked->cancellation_reason = 'customer';
                $locked->cancelled_at = Carbon::now();
                $locked->save();

                /** @var Order $order */
                $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                $this->totals->recalculateAndSave($order);

                $this->audit->log(
                    'table.item.cancelled_by_customer',
                    user: null,
                    auditable: $locked,
                    data: [
                        'order_id' => $locked->order_id,
                        'guest_id' => $guest->id,
                        'reason' => $reason,
                    ],
                    request: $request,
                );

                return ['kind' => 'cancelled', 'item' => $locked, 'request' => null];
            }

            if ($locked->status === 'approved') {
                $existing = CancellationRequest::query()
                    ->where('order_item_id', $locked->id)
                    ->where('status', 'pending')
                    ->first();

                if ($existing !== null) {
                    return ['kind' => 'request_created', 'item' => $locked, 'request' => $existing];
                }

                $cr = new CancellationRequest;
                $cr->order_item_id = $locked->id;
                $cr->guest_id = $guest->id;
                $cr->status = 'pending';
                $cr->reason = $reason;
                $cr->save();

                $this->audit->log(
                    'table.item.cancellation_requested',
                    user: null,
                    auditable: $cr,
                    data: [
                        'order_id' => $locked->order_id,
                        'order_item_id' => $locked->id,
                        'guest_id' => $guest->id,
                        'reason' => $reason,
                    ],
                    request: $request,
                );

                return ['kind' => 'request_created', 'item' => $locked, 'request' => $cr];
            }

            throw new InvalidArgumentException(
                'Este item ya entró a cocina. Pídele al mesero que lo cancele si es necesario.'
            );
        });
    }

    /**
     * Marca todos los items `pending_approval` del comensal como "enviados al
     * mesero" (setea `submitted_at`). No cambia el estado — eso lo hace el
     * mesero cuando aprueba.
     */
    public function submitBatch(TableSessionGuest $guest, Request $request): int
    {
        return DB::transaction(function () use ($guest, $request) {
            /** @var TableSession $session */
            $session = $guest->session()->lockForUpdate()->firstOrFail();
            $this->guardSessionAllowsChanges($session);

            $now = Carbon::now();

            $affected = OrderItem::query()
                ->where('guest_id', $guest->id)
                ->where('status', 'pending_approval')
                ->whereNull('submitted_at')
                ->update(['submitted_at' => $now]);

            if ($affected > 0) {
                $this->bumpExpiry($session);

                $this->audit->log(
                    'table.batch.submitted',
                    user: null,
                    auditable: $session,
                    data: [
                        'table_session_id' => $session->id,
                        'guest_id' => $guest->id,
                        'items_submitted' => $affected,
                    ],
                    request: $request,
                );
            }

            return (int) $affected;
        });
    }

    /**
     * Crea una nota asociada a la orden de la sesión. Si la orden aún no
     * existe (nadie agregó items todavía), la crea lazy.
     */
    public function addNote(
        TableSessionGuest $guest,
        string $scope,
        string $body,
        Request $request,
    ): OrderNote {
        if (! in_array($scope, ['group', 'kitchen_alert'], true)) {
            throw new InvalidArgumentException('Scope de nota inválido.');
        }

        $body = mb_substr(trim($body), 0, 500);
        if ($body === '') {
            throw new InvalidArgumentException('La nota no puede ir vacía.');
        }

        return DB::transaction(function () use ($guest, $scope, $body, $request) {
            /** @var TableSession $session */
            $session = $guest->session()->lockForUpdate()->firstOrFail();
            $this->guardSessionAllowsChanges($session);

            $order = $this->resolveOrderForSession($session);

            $note = new OrderNote;
            $note->order_id = $order->id;
            $note->scope = $scope;
            $note->body = $body;
            $note->author()->associate($guest);
            $note->save();

            $this->audit->log(
                $scope === 'kitchen_alert' ? 'table.note.kitchen_alert_added' : 'table.note.group_added',
                user: null,
                auditable: $note,
                data: [
                    'order_id' => $order->id,
                    'guest_id' => $guest->id,
                    'scope' => $scope,
                ],
                request: $request,
            );

            return $note;
        });
    }

    /**
     * Snapshot del estado de la sesión y del carrito del comensal (lectura,
     * sin lock). Usado por el polling del frontend.
     *
     * Devuelve `guests[]` con teléfono completo (sin máscara) porque ya están
     * dentro de la sesión y el listado es visible solo a comensales de la
     * misma mesa con cookie firmada — no es PII pública.
     *
     * @return array{
     *     session: array{id: int, status: string, expires_at: ?string},
     *     order: ?array{id: int, status: string, total: string},
     *     current_guest_id: int,
     *     guests: list<array{id:int, display_name:string, phone:string, joined_at:?string, is_self:bool}>,
     *     my_items: list<array{id:int, menu_item_id:string, name:string, quantity:int, unit_price:string, notes:?string, status:string, cancellation_reason:?string, submitted_at:?string}>,
     *     group_notes: list<array{id:int, scope:string, body:string, created_at:?string}>,
     *     pending_cancellations: list<array{id:int, order_item_id:int, status:string, reason:?string}>
     * }
     */
    public function stateFor(TableSessionGuest $guest): array
    {
        $session = $guest->session;

        // Todas las órdenes de la sesión (buffer + aprobadas). Se leen todas
        // para que el comensal vea el historial completo de su pedido aunque
        // approveBatch haya movido sus items a una orden nueva.
        $orderIds = Order::withoutGlobalScopes()
            ->where('table_session_id', $session->id)
            ->pluck('id');

        $bufferOrder = $orderIds->isEmpty()
            ? null
            : Order::withoutGlobalScopes()
                ->where('table_session_id', $session->id)
                ->where('status', 'pending_approval')
                ->first();

        $guests = TableSessionGuest::query()
            ->where('table_session_id', $session->id)
            ->orderBy('joined_at')
            ->get(['id', 'display_name', 'phone', 'joined_at'])
            ->map(fn (TableSessionGuest $g) => [
                // guests.id es uuid: NUNCA (int) — devolvía PHP_INT_MAX para
                // todos, rompiendo identificación y is_self en el frontend.
                'id' => (string) $g->id,
                'display_name' => $g->display_name,
                'phone' => $g->phone,
                'joined_at' => optional($g->joined_at)?->toIso8601String(),
                'is_self' => (string) $g->id === (string) $guest->id,
            ])
            ->all();

        $myItems = $orderIds->isEmpty()
            ? []
            : OrderItem::query()
                ->whereIn('order_id', $orderIds)
                ->where('guest_id', $guest->id)
                ->orderBy('submitted_at')
                ->orderBy('id')
                ->get(['id', 'menu_item_id', 'name', 'quantity', 'unit_price', 'notes', 'status', 'cancellation_reason', 'submitted_at'])
                ->map(fn (OrderItem $i) => [
                    'id' => $i->id,
                    'menu_item_id' => $i->menu_item_id,
                    'name' => $i->name,
                    'quantity' => (int) $i->quantity,
                    'unit_price' => (string) $i->unit_price,
                    'notes' => $i->notes,
                    'status' => $i->status,
                    'cancellation_reason' => $i->cancellation_reason,
                    'submitted_at' => optional($i->submitted_at)?->toIso8601String(),
                ])
                ->all();

        $guestsByIdForNotes = collect($guests)->keyBy('id');

        $notes = $orderIds->isEmpty()
            ? []
            : OrderNote::query()
                ->whereIn('order_id', $orderIds)
                ->orderBy('id')
                ->get(['id', 'scope', 'body', 'author_type', 'author_id', 'created_at'])
                ->map(function (OrderNote $n) use ($guestsByIdForNotes) {
                    $authorLabel = null;
                    if ($n->author_type === TableSessionGuest::class && $n->author_id !== null) {
                        $g = $guestsByIdForNotes->get($n->author_id);
                        $authorLabel = $g['display_name'] ?? null;
                    } elseif ($n->author_type !== null && str_contains((string) $n->author_type, 'User')) {
                        $authorLabel = 'Mesero';
                    }

                    return [
                        'id' => $n->id,
                        'scope' => $n->scope,
                        'body' => $n->body,
                        'created_at' => optional($n->created_at)?->toIso8601String(),
                        'author_label' => $authorLabel,
                    ];
                })
                ->all();

        $pendingCancellations = CancellationRequest::query()
            ->whereIn('order_item_id', array_column($myItems, 'id'))
            ->where('status', 'pending')
            ->get(['id', 'order_item_id', 'status', 'reason'])
            ->map(fn (CancellationRequest $cr) => [
                'id' => $cr->id,
                'order_item_id' => $cr->order_item_id,
                'status' => $cr->status,
                'reason' => $cr->reason,
            ])
            ->all();

        // Total de sesión calculado sobre todos los items no cancelados del
        // comensal (no solo el buffer). El frontend ya lo recomputa de my_items,
        // pero devolvemos el valor server-side para consistencia.
        $sessionTotal = array_sum(array_map(
            fn ($i) => $i['status'] !== 'cancelled' ? (float) $i['unit_price'] * $i['quantity'] : 0.0,
            $myItems,
        ));

        return [
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'expires_at' => optional($session->expires_at)?->toIso8601String(),
            ],
            'order' => $bufferOrder !== null ? [
                'id' => $bufferOrder->id,
                'status' => $bufferOrder->status,
                'total' => number_format($sessionTotal, 2, '.', ''),
            ] : ($sessionTotal > 0 ? [
                'id' => null,
                'status' => 'active',
                'total' => number_format($sessionTotal, 2, '.', ''),
            ] : null),
            // guests.id es uuid → cast string, no int (rompía con PHP_INT_MAX).
            'current_guest_id' => (string) $guest->id,
            'guests' => $guests,
            'my_items' => $myItems,
            'group_notes' => $notes,
            'pending_cancellations' => $pendingCancellations,
        ];
    }

    /**
     * Resuelve (o crea lazy) la orden asociada a la sesión de mesa.
     *
     * Naming: una orden por sesión. Estado inicial `pending_approval` (config
     * #191) — el mesero la promueve a `pending` cuando aprueba la primera
     * tanda. `total` arranca en 0 y lo recalcula `OrderTotalCalculator`.
     */
    private function resolveOrderForSession(TableSession $session): Order
    {
        $order = Order::withoutGlobalScopes()
            ->where('table_session_id', $session->id)
            ->where('status', 'pending_approval')
            ->lockForUpdate()
            ->first();

        if ($order !== null) {
            return $order;
        }

        // `order_type='table'` y `table_number` poblado son los criterios del
        // tablero de mesas (`GET /api/orders/tables`). Sin esto, la orden de
        // la sesión grupal no aparece en /orders aunque tenga items aprobados.
        $tableNumber = Table::withoutGlobalScopes()
            ->whereKey($session->table_id)
            ->value('number');

        $order = new Order;
        $order->company_nit = $session->company_nit;
        $order->branch_id = $session->branch_id;
        $order->table_session_id = $session->id;
        $order->status = 'pending_approval';
        $order->order_type = 'table';
        $order->table_number = $tableNumber;
        $order->total = '0.00';
        $order->subtotal = '0.00';
        $order->ordered_at = Carbon::now();

        // Snapshot tributario al nacer la orden (paridad con el flujo de caja).
        // OrderTotalCalculator usa este snapshot (no el estado vivo de la
        // empresa) para el desglose subtotal/tax_amount de la cuenta (#293).
        $company = Company::query()
            ->where('nit', $session->company_nit)
            ->first(['nit', 'default_tax_rate', 'tax_regime', 'tax_included_in_price']);
        if ($company !== null) {
            $order->snapshot_default_tax_rate = (float) $company->default_tax_rate;
            $order->tax_regime = $company->tax_regime;
            $order->tax_included_in_price = (bool) $company->tax_included_in_price;
        }

        $order->save();

        return $order;
    }

    /**
     * Resuelve un item del menú activo de la sede de la sesión. Lanza si no
     * existe o el menú está inactivo — eso evita que el cliente meta un
     * `menu_item_id` inventado. Filtra por `branch_id` porque una empresa
     * puede tener un menú activo por sede; sin este filtro, podríamos resolver
     * el item contra el menú de otra sede (o no encontrarlo aunque exista en
     * la sede del comensal).
     *
     * @return array<string, mixed>
     */
    private function resolveMenuItem(string $companyNit, string $branchId, string $menuItemId): array
    {
        $menu = RestaurantMenu::query()
            ->withoutGlobalScopes()
            ->forCompany($companyNit)
            ->where('branch_id', $branchId)
            ->active()
            ->orderByDesc('updated_at')
            ->first();

        if ($menu === null) {
            throw new InvalidArgumentException('La empresa no tiene un menú activo en este momento.');
        }

        $item = $menu->findMenuItem($menuItemId);
        if ($item === null) {
            throw new InvalidArgumentException('El plato seleccionado no está disponible.');
        }

        return $item;
    }

    /**
     * Asegura que la sesión está en estado que permite mutaciones del cliente.
     */
    private function guardSessionAllowsChanges(TableSession $session): void
    {
        if (in_array($session->status, ['closed', 'expired'], true)) {
            throw new InvalidArgumentException('La sesión de mesa ya está cerrada.');
        }
    }

    /**
     * Renueva `expires_at` de la sesión desde ahora + config de expiración.
     * Llamado tras cada acción del comensal para que el reloj de inactividad
     * corra desde la última interacción, no desde la apertura.
     */
    private function bumpExpiry(TableSession $session): void
    {
        $hours = (int) config('tables.session_expiration_hours', 1);
        $session->expires_at = Carbon::now()->addHours($hours);
        $session->save();
    }

    /**
     * Sanitiza notas: trim, strip tags básicos, max 500 chars.
     */
    private function sanitizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }
        $clean = trim(strip_tags($notes));
        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, 500);
    }
}
