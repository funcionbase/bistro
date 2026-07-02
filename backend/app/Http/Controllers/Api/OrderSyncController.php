<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\OfflineSyncEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentReceipt;
use App\Models\RestaurantMenu;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\CashRegisterService;
use App\Services\FeaturePermissionService;
use App\Services\Sms\OrderStatusSmsDispatcher;
use App\Services\TaxCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sincroniza órdenes y cobros creados en modo offline (#140).
 *
 * Endpoint: `POST /api/v1/orders/sync-batch`. Recibe un batch con múltiples
 * órdenes (cada una con un `client_uuid` UUID v4 generado en el navegador) y,
 * opcionalmente, un cobro asociado.
 *
 * Garantías:
 *  - Idempotente por `(company_nit, client_uuid)`: reintentos del mismo batch
 *    NO crean duplicados ni doble-cobros (CLAUDE.md: receipts inmutables).
 *  - Multitenant estricto: el `company_nit` se resuelve SOLO del JWT activo;
 *    cualquier `company_nit` en el payload se valida y, si no coincide, se
 *    rechaza el batch completo (defensa en profundidad).
 *  - Re-validación server-side de precios e items: el cliente no inyecta
 *    precios; se leen del menú activo en BD.
 *  - Warnings (item no disponible ahora, skew temporal) se persisten en
 *    `orders.sync_warnings` pero NO rechazan la orden (el cliente real ya
 *    consumió en sitio).
 *
 * Respuesta: por cada orden del batch un objeto con `status` ∈
 * `created | duplicate | warning | failed` y el `server_id` correspondiente.
 */
class OrderSyncController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly AuditService $auditService,
        private readonly CashRegisterService $cashRegister,
        private readonly OrderController $orderController,
        private readonly OrderStatusSmsDispatcher $smsDispatcher,
    ) {}

    public function syncBatch(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'create');

        $validated = $request->validate([
            'orders' => ['required', 'array', 'min:1', 'max:100'],
            'orders.*.client_uuid' => ['required', 'uuid'],
            'orders.*.company_nit' => ['required', 'string'],
            'orders.*.order_type' => ['required', 'string'],
            'orders.*.client_phone' => ['nullable', new SafePlainText(maxBytes: 32, allowWhitespace: false)],
            'orders.*.table_number' => ['nullable', new SafePlainText(maxBytes: 20, allowWhitespace: false)],
            'orders.*.delivery_address' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
            'orders.*.items' => ['required', 'array', 'min:1'],
            'orders.*.items.*.id' => ['required', 'string'],
            'orders.*.items.*.quantity' => ['required', 'integer', 'min:1'],
            'orders.*.items.*.notes' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
            'orders.*.created_at' => ['required', 'date'],
            'orders.*.payment' => ['nullable', 'array'],
            'orders.*.payment.client_uuid' => ['required_with:orders.*.payment', 'uuid'],
            'orders.*.payment.method' => ['required_with:orders.*.payment', 'in:cash,card,transfer'],
            'orders.*.payment.amount_received' => ['nullable', 'numeric', 'min:0'],
            'orders.*.payment.tip_amount' => ['nullable', 'numeric', 'min:0'],
            'orders.*.payment.reference' => ['nullable', 'string', 'max:120'],
            'orders.*.payment.paid_at' => ['required_with:orders.*.payment', 'date'],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $actingUser = $this->actingUser($request);

        // Defensa en profundidad: rechazar el batch entero si cualquier orden
        // viene marcada con un company_nit distinto al de la sesión activa.
        // Esto evita que un cliente comprometido empuje órdenes a otra empresa.
        foreach ($validated['orders'] as $idx => $payloadOrder) {
            if ($payloadOrder['company_nit'] !== $companyNit) {
                throw ValidationException::withMessages([
                    "orders.{$idx}.company_nit" => 'El batch contiene órdenes de otra empresa. Sincronización rechazada.',
                ]);
            }
        }

        // Multi-sede (#117): la sesión de caja se resuelve por SEDE activa, no
        // por empresa (evita atribuir cobros offline a la caja de otra sede).
        $session = $this->cashRegister->activeSessionForBranch($companyNit, $this->activeBranchId($request));
        $company = Company::where('nit', $companyNit)->firstOrFail();
        $menu = RestaurantMenu::forCompany($companyNit)->active()->first();
        $catalog = $menu ? $this->orderController->buildMenuCatalog($menu) : collect();

        $results = [];
        $aggregateOrders = ['count' => 0, 'amount' => 0.0];
        $aggregateReceipts = ['count' => 0, 'amount' => 0.0];
        $aggregateFailed = 0;

        foreach ($validated['orders'] as $payloadOrder) {
            try {
                $result = $this->syncSingle(
                    payloadOrder: $payloadOrder,
                    companyNit: $companyNit,
                    company: $company,
                    catalog: $catalog,
                    session: $session,
                    actingUser: $actingUser,
                    request: $request,
                );

                $results[] = $result;

                if ($result['status'] === 'created' || $result['status'] === 'warning') {
                    $aggregateOrders['count']++;
                    $aggregateOrders['amount'] += (float) ($result['total'] ?? 0);
                }

                // Independiente del status: un reintento `duplicate` también puede
                // aplicar un cobro que quedó pendiente (caja cerrada en el intento
                // original).
                if (! empty($result['receipt_created'])) {
                    $aggregateReceipts['count']++;
                    $aggregateReceipts['amount'] += (float) ($result['total'] ?? 0);

                    // SMS al cliente (#275) FUERA de la txn de `syncSingle` (ya
                    // commiteada): la orden offline con pago incluido llega
                    // directo a `completed` y antes nunca notificaba.
                    if (! empty($result['server_id'])) {
                        $paidOrder = Order::withoutGlobalScopes()->find($result['server_id']);
                        if ($paidOrder !== null) {
                            $this->smsDispatcher->dispatch($paidOrder, 'completed', $actingUser);
                        }
                    }
                }
            } catch (Throwable $e) {
                $aggregateFailed++;
                $results[] = [
                    'client_uuid' => $payloadOrder['client_uuid'],
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->recordEvents($companyNit, (string) $this->activeBranchId($request), $actingUser?->id, $aggregateOrders, $aggregateReceipts, $aggregateFailed);

        return response()->json([
            'results' => $results,
            'summary' => [
                'orders_synced' => $aggregateOrders['count'],
                'receipts_synced' => $aggregateReceipts['count'],
                'failed' => $aggregateFailed,
            ],
        ]);
    }

    /**
     * Persiste una orden + cobro idempotentemente. Devuelve el resultado por orden.
     *
     * @param  array<string, mixed>  $payloadOrder
     * @param  Collection<string, array<string, mixed>>  $catalog
     * @return array<string, mixed>
     */
    private function syncSingle(
        array $payloadOrder,
        string $companyNit,
        Company $company,
        $catalog,
        $session,
        ?User $actingUser,
        Request $request,
    ): array {
        return DB::transaction(function () use ($payloadOrder, $companyNit, $company, $catalog, $session, $actingUser, $request) {
            // Idempotencia: lock por client_uuid. Si la orden ya existe, devolvemos
            // su id sin re-procesar — clave para no doble-cobrar (CLAUDE.md).
            $existing = Order::query()
                ->where('company_nit', $companyNit)
                ->where('client_uuid', $payloadOrder['client_uuid'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // Reintento de un batch cuyo pago pudo NO haberse aplicado en el
                // intento original (p. ej. caja cerrada en ese momento). El
                // helper es idempotente: si el receipt ya existe o la orden ya
                // fue cobrada por otro camino, no hace nada.
                $receiptCreated = ! empty($payloadOrder['payment'])
                    ? $this->applyPaymentIfPending($existing, $payloadOrder['payment'], $session)
                    : false;

                return [
                    'client_uuid' => $payloadOrder['client_uuid'],
                    'status' => 'duplicate',
                    'server_id' => $existing->id,
                    'total' => (float) $existing->total,
                    'receipt_created' => $receiptCreated,
                ];
            }

            // Re-validación de items contra el menú activo. El cliente NUNCA
            // inyecta precios — se leen del menú en BD (regla CLAUDE.md).
            if ($catalog->isEmpty()) {
                throw ValidationException::withMessages([
                    'menu' => 'No hay un menú activo para sincronizar la orden.',
                ]);
            }

            $warnings = [];
            $payloadItems = [];
            foreach ($payloadOrder['items'] as $line) {
                $catalogItem = $catalog->get($line['id']);
                if (! $catalogItem) {
                    throw ValidationException::withMessages([
                        'items' => "El ítem {$line['id']} no existe en el menú activo.",
                    ]);
                }
                if (! ($catalogItem['available'] ?? true)) {
                    // Warning, NO rechazo: el cliente real ya consumió en sitio.
                    $warnings[] = [
                        'type' => 'item_unavailable',
                        'menu_item_id' => $line['id'],
                        'sold_at' => $payloadOrder['created_at'],
                    ];
                }
                $payloadItems[] = $line;
            }

            $items = $this->orderController->buildOrderLines($payloadItems, $catalog, $company, (string) $this->activeBranchId($request));
            $aggregate = app(TaxCalculator::class)->aggregate($items);

            // Skew temporal: si la diferencia entre `created_at` del cliente y
            // ahora supera 24h, flagear (puede indicar reloj manipulado).
            $orderedAt = Carbon::parse($payloadOrder['created_at']);
            if (abs(now()->diffInHours($orderedAt, false)) > 24) {
                $warnings[] = [
                    'type' => 'clock_skew',
                    'client_at' => $orderedAt->toIso8601String(),
                    'server_at' => now()->toIso8601String(),
                ];
            }

            $order = Order::create([
                'company_nit' => $companyNit,
                'branch_id' => $this->activeBranchId($request),
                'client_uuid' => $payloadOrder['client_uuid'],
                'client_phone' => $payloadOrder['client_phone'] ?? null,
                'order_type' => $payloadOrder['order_type'],
                'table_number' => $payloadOrder['order_type'] === 'table' ? ($payloadOrder['table_number'] ?? null) : null,
                'delivery_address' => $payloadOrder['order_type'] === 'delivery' ? ($payloadOrder['delivery_address'] ?? null) : null,
                'items' => $items,
                'status' => 'pending',
                'subtotal' => $aggregate['subtotal'],
                'tax_amount' => $aggregate['tax_amount'],
                'tax_rate' => $aggregate['effective_rate'],
                'total' => $aggregate['total'],
                'snapshot_default_tax_rate' => (float) $company->default_tax_rate,
                'tax_regime' => $company->tax_regime,
                'tax_included_in_price' => (bool) $company->tax_included_in_price,
                'cost' => $this->orderController->computeOrderCost($items),
                'discount_amount' => 0,
                'ordered_at' => $orderedAt,
                'sync_warnings' => $warnings ?: null,
            ]);

            // Materializar filas `order_items` (#293): sin esto la orden
            // sincronizada quedaba invisible para el KDS y el pago por item.
            $this->orderController->materializeOrderItems($order, $items);

            $receiptCreated = ! empty($payloadOrder['payment'])
                ? $this->applyPaymentIfPending($order, $payloadOrder['payment'], $session)
                : false;

            $this->auditService->log('order.synced_offline', $actingUser, $order, [
                'order_id' => $order->id,
                'client_uuid' => $payloadOrder['client_uuid'],
                'warnings' => $warnings,
                'receipt_created' => $receiptCreated,
                'total' => (float) $order->total,
            ], $request);

            return [
                'client_uuid' => $payloadOrder['client_uuid'],
                'status' => $warnings ? 'warning' : 'created',
                'server_id' => $order->id,
                'total' => (float) $order->total,
                'warnings' => $warnings,
                'receipt_created' => $receiptCreated,
            ];
        });
    }

    /**
     * Aplica el cobro offline de una orden si aún está pendiente. Idempotente:
     *  - Receipt ya existe por `client_uuid` → no-op (reintento del batch).
     *  - La orden ya fue cobrada por otro camino (receipt no-refund o estado
     *    terminal de éxito) → no-op; nunca se crea un segundo asiento.
     *  - Sin caja abierta → lanza: la txn del caller revierte, la orden queda
     *    `failed` en el batch y el cliente reintenta con backoff cuando la caja
     *    abra. Antes el cobro se saltaba en silencio y, como el reintento caía
     *    en `duplicate` sin procesar pagos, el receipt se perdía para siempre.
     *
     * Debe correr dentro de la transacción del caller con la orden bloqueada.
     *
     * @param  array<string, mixed>  $payment
     */
    private function applyPaymentIfPending(Order $order, array $payment, ?CashRegisterSession $session): bool
    {
        $existingReceipt = PaymentReceipt::query()
            ->where('client_uuid', $payment['client_uuid'])
            ->lockForUpdate()
            ->first();
        if ($existingReceipt !== null) {
            return false;
        }

        $alreadyPaid = $order->receipts()->where('payment_method', '!=', 'refund')->exists();
        if ($alreadyPaid || in_array($order->status, (array) config('orders.terminal_success'), true)) {
            return false;
        }

        if ($session === null) {
            throw ValidationException::withMessages([
                'payment' => 'No hay caja abierta en la sede para aplicar el cobro offline. Abre la caja y reintenta la sincronización.',
            ]);
        }

        $tip = round((float) ($payment['tip_amount'] ?? 0), 2);
        $total = (float) $order->total;
        $expectedTotal = round($total + $tip, 2);
        $paidAt = Carbon::parse($payment['paid_at']);

        $paymentData = [
            'method' => $payment['method'],
            'total' => $total,
            'tip_amount' => $tip,
            'expected_total' => $expectedTotal,
            'paid_at' => $payment['paid_at'],
            'reference' => $payment['reference'] ?? null,
            'synced_offline' => true,
        ];

        if ($payment['method'] === 'cash' && isset($payment['amount_received'])) {
            $paymentData['amount_received'] = (float) $payment['amount_received'];
            $paymentData['change_returned'] = round($paymentData['amount_received'] - $expectedTotal, 2);
        }

        $receipt = PaymentReceipt::create([
            'order_id' => $order->id,
            'company_nit' => $order->company_nit,
            'branch_id' => $order->branch_id,
            'client_uuid' => $payment['client_uuid'],
            'file_path' => null,
            'payment_method' => $payment['method'],
            'amount' => $total,
            'reference' => $payment['reference'] ?? null,
            'paid_at' => $paidAt,
            'cash_session_id' => $session->id,
            'payment_data' => $paymentData,
        ]);

        $order->tip_amount = $tip;
        $order->status = 'completed';
        $order->save();

        // KDS: items abiertos pasan a `served` (la orden ya quedó completed —
        // antes quedaban en `approved` para siempre). Luego el stamping de pago
        // para que el cobro de mesa no los vea como pendientes.
        OrderItem::query()
            ->where('order_id', $order->id)
            ->whereIn('status', (array) config('orders.item_statuses.operational'))
            ->update(['status' => 'served', 'served_at' => now()]);

        $this->orderController->markOrderItemsPaid($order, $receipt->id, $paidAt);

        return true;
    }

    /**
     * @param  array{count: int, amount: float}  $orders
     * @param  array{count: int, amount: float}  $receipts
     */
    private function recordEvents(string $companyNit, string $branchId, ?string $userId, array $orders, array $receipts, int $failed): void
    {
        $now = now();
        if ($orders['count'] > 0) {
            OfflineSyncEvent::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'event_type' => 'order_synced',
                'count' => $orders['count'],
                'total_amount' => round($orders['amount'], 2),
                'occurred_at' => $now,
            ]);
        }
        if ($receipts['count'] > 0) {
            OfflineSyncEvent::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'event_type' => 'receipt_synced',
                'count' => $receipts['count'],
                'total_amount' => round($receipts['amount'], 2),
                'occurred_at' => $now,
            ]);
        }
        if ($failed > 0) {
            OfflineSyncEvent::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'event_type' => 'sync_failed',
                'count' => $failed,
                'total_amount' => 0,
                'occurred_at' => $now,
            ]);
        }
    }
}
