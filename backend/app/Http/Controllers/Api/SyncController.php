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
use App\Services\AuditService;
use App\Services\CashRegisterService;
use App\Services\FeaturePermissionService;
use App\Services\InventoryService;
use App\Services\Sms\OrderStatusSmsDispatcher;
use App\Services\TaxCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Endpoint de sincronización unificado para la caja offline-first
 * (plan-off.md §6.1). Drena el OUTBOX del cliente: una lista ordenada de
 * operaciones tipadas (`order.create`, `order.close`, …) generadas offline.
 *
 * Garantías (plan §3, §13):
 *  - Source of truth = servidor. El cliente envía intenciones; el server
 *    valida, recalcula precios/total desde el menú activo y su resultado manda.
 *  - Idempotencia extremo a extremo: cada op lleva `op_id` (idempotency-key) y,
 *    donde aplica, `client_uuid` con UNIQUE en BD. Reintentos NUNCA duplican.
 *    `Cache::lock("sync:{op_id}")` (store compartido) hace la dedup N-instance.
 *  - Contabilidad inmutable y aditiva: receipts se INSERTAN, nunca se editan.
 *  - Orden causal: el cliente ya ordena topológicamente; `depends_on`/`entity_ref`
 *    resuelven `client_uuid → server_id` dentro del mismo lote (id_map en memoria).
 *  - RBAC por-op: el permiso se valida POR operación (un lote mezcla tipos).
 *
 * DIAN es online-only: la emisión de documentos electrónicos NO ocurre acá; se
 * difiere a un flujo posterior (tiquete provisional offline, plan §1/§10).
 */
class SyncController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    /**
     * Permiso requerido por tipo de op: `[featureGroup, action]`. Reutiliza los
     * permisos existentes (plan §11) — operar offline NO amplía lo permitido.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const OP_PERMISSIONS = [
        'order.create' => ['orders', 'create'],
        'order.close' => ['orders', 'update'],
        'cash.open' => ['orders', 'create'],
        'cash.expense' => ['orders', 'update'],
        'cash.income' => ['orders', 'update'],
        'cash.close' => ['orders', 'update'],
    ];

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly AuditService $auditService,
        private readonly CashRegisterService $cashRegister,
        private readonly OrderController $orderController,
        private readonly OrderStatusSmsDispatcher $smsDispatcher,
        private readonly InventoryService $inventoryService,
    ) {}

    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ops' => ['required', 'array', 'min:1', 'max:200'],
            'ops.*.op_id' => ['required', 'uuid'],
            'ops.*.type' => ['required', 'string'],
            'ops.*.company_nit' => ['required', 'string'],
            'ops.*.branch_id' => ['nullable', 'string'],
            'ops.*.payload' => ['required', 'array'],
            'ops.*.entity_ref' => ['nullable', 'string'],
            'ops.*.depends_on' => ['nullable', 'array'],
            'ops.*.created_at_client' => ['nullable', 'date'],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $actingUser = $this->actingUser($request);

        // Multitenant strict (defensa en profundidad): el batch entero se
        // rechaza si cualquier op trae un company_nit distinto al de la sesión.
        foreach ($validated['ops'] as $idx => $op) {
            if ($op['company_nit'] !== $companyNit) {
                return response()->json([
                    'message' => 'El batch contiene operaciones de otra empresa. Sincronización rechazada.',
                    'errors' => ["ops.{$idx}.company_nit" => ['empresa no coincide con la sesión activa']],
                ], 422);
            }
        }

        $company = Company::where('nit', $companyNit)->firstOrFail();
        $menu = RestaurantMenu::forCompany($companyNit)->active()->first();
        $catalog = $menu ? $this->orderController->buildMenuCatalog($menu) : collect();

        /** @var array<string, string> $idMap mapa client_uuid(entity_ref) → server_id dentro del lote */
        $idMap = [];
        $results = [];
        $aggregateOrders = ['count' => 0, 'amount' => 0.0];
        $aggregateReceipts = ['count' => 0, 'amount' => 0.0];
        $aggregateFailed = 0;

        foreach ($validated['ops'] as $op) {
            $opId = $op['op_id'];
            $type = $op['type'];

            try {
                // Tipo soportado en esta fase.
                if (! isset(self::OP_PERMISSIONS[$type])) {
                    $results[] = ['op_id' => $opId, 'status' => 'conflict', 'code' => 'unsupported_op_type'];

                    continue;
                }

                // RBAC por-op: el server revalida aunque el cliente haya filtrado
                // con permisos cacheados (best-effort, plan §11).
                [$feature, $action] = self::OP_PERMISSIONS[$type];
                if (! $this->permissionService->hasPermission($request, $feature, $action)) {
                    $results[] = ['op_id' => $opId, 'status' => 'conflict', 'code' => 'forbidden'];

                    continue;
                }

                // Idempotencia N-instance: lock por op_id en store compartido.
                // Las ops que necesitan sesión la re-resuelven adentro (puede
                // haberse abierto por un `cash.open` anterior del mismo lote).
                $result = Cache::lock("sync:{$opId}", 10)->block(5, function () use ($op, $type, $companyNit, $branchId, $company, $catalog, $actingUser, $request, &$idMap) {
                    return match ($type) {
                        'order.create' => $this->applyOrderCreate($op, $companyNit, $branchId, $company, $catalog, $actingUser, $request),
                        'order.close' => $this->applyOrderClose($op, $companyNit, $branchId, $actingUser, $request, $idMap),
                        'cash.open' => $this->applyCashOpen($op, $companyNit, $branchId, $actingUser),
                        'cash.expense' => $this->applyCashExpense($op, $companyNit, $branchId, $actingUser),
                        'cash.income' => $this->applyCashIncome($op, $companyNit, $branchId, $actingUser),
                        'cash.close' => $this->applyCashClose($op, $companyNit, $branchId, $actingUser, $request),
                        default => ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'unsupported_op_type'],
                    };
                });

                // Alimentar el id_map para resolver dependencias de ops siguientes.
                if (! empty($op['entity_ref']) && ! empty($result['server_id'])) {
                    $idMap[$op['entity_ref']] = $result['server_id'];
                }

                // SMS al cliente FUERA de la txn de `applyOrderClose` (ya
                // commiteada al volver acá): antes este camino de cobro offline
                // nunca notificaba, solo `OrderController::closeWithPayment`.
                if ($type === 'order.close' && ($result['status'] ?? null) === 'created' && ! empty($result['server_id'])) {
                    $closedOrder = Order::withoutGlobalScopes()->find($result['server_id']);
                    if ($closedOrder !== null) {
                        $this->smsDispatcher->dispatch($closedOrder, 'completed', $actingUser);
                    }
                }

                $results[] = $result;

                $status = $result['status'] ?? 'failed';
                if ($status === 'created' || $status === 'warning') {
                    $aggregateOrders['count']++;
                    $aggregateOrders['amount'] += (float) ($result['total'] ?? 0);
                }
                if (! empty($result['receipt_created'])) {
                    $aggregateReceipts['count']++;
                    $aggregateReceipts['amount'] += (float) ($result['amount'] ?? 0);
                }
            } catch (Throwable $e) {
                $aggregateFailed++;
                $results[] = ['op_id' => $opId, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        $this->recordEvents($companyNit, $branchId, $actingUser?->id, $aggregateOrders, $aggregateReceipts, $aggregateFailed);

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
     * Crea una orden offline idempotente por `client_uuid`. Reusa los helpers de
     * `OrderController` (precios/impuestos desde el menú activo en BD).
     *
     * @param  array<string, mixed>  $op
     * @param  Collection<string, array<string, mixed>>  $catalog
     * @return array<string, mixed>
     */
    private function applyOrderCreate(array $op, string $companyNit, string $branchId, Company $company, Collection $catalog, ?User $actingUser, Request $request): array
    {
        $payload = $op['payload'];
        $clientUuid = $payload['client_uuid'] ?? null;
        if (! is_string($clientUuid) || $clientUuid === '') {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'missing_client_uuid'];
        }

        return DB::transaction(function () use ($op, $payload, $clientUuid, $companyNit, $branchId, $company, $catalog, $actingUser, $request) {
            // Idempotencia: si la orden ya existe, devolver su id sin re-procesar.
            $existing = Order::query()
                ->where('company_nit', $companyNit)
                ->where('client_uuid', $clientUuid)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return ['op_id' => $op['op_id'], 'status' => 'duplicate', 'server_id' => $existing->id, 'total' => (float) $existing->total];
            }

            if ($catalog->isEmpty()) {
                return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'no_active_menu'];
            }

            $warnings = [];
            $payloadItems = [];
            foreach (($payload['items'] ?? []) as $line) {
                $catalogItem = $catalog->get($line['id'] ?? null);
                if (! $catalogItem) {
                    return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'item_not_found', 'menu_item_id' => $line['id'] ?? null];
                }
                if (! ($catalogItem['available'] ?? true)) {
                    // Warning, NO rechazo: el cliente real ya consumió en sitio.
                    $warnings[] = ['type' => 'item_unavailable', 'menu_item_id' => $line['id'], 'sold_at' => $op['created_at_client'] ?? null];
                }
                $payloadItems[] = $line;
            }

            $items = $this->orderController->buildOrderLines($payloadItems, $catalog, $company, $branchId);

            // Paridad con el flujo online (F1): las órdenes delivery cargan la
            // línea de domicilio también en el replay offline — sin esto el
            // total sincronizado quedaba sin fee y descuadraba contra caja.
            if (($payload['order_type'] ?? null) === 'delivery') {
                $items = $this->orderController->appendDeliveryFeeLine($items, $branchId, $company);
            }

            $aggregate = app(TaxCalculator::class)->aggregate($items);

            $clientAt = isset($op['created_at_client']) ? Carbon::parse($op['created_at_client']) : now();
            if (abs(now()->diffInHours($clientAt, false)) > 24) {
                $warnings[] = ['type' => 'clock_skew', 'client_at' => $clientAt->toIso8601String(), 'server_at' => now()->toIso8601String()];
            }

            $order = Order::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'client_uuid' => $clientUuid,
                'client_phone' => $payload['client_phone'] ?? null,
                'order_type' => $payload['order_type'] ?? 'pickup',
                'table_number' => ($payload['order_type'] ?? null) === 'table' ? ($payload['table_number'] ?? null) : null,
                'delivery_address' => ($payload['order_type'] ?? null) === 'delivery' ? ($payload['delivery_address'] ?? null) : null,
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
                'ordered_at' => $clientAt,
                'created_at_client' => $clientAt,
                'is_offline_origin' => true,
                'sync_warnings' => $warnings ?: null,
            ]);

            // Materializar filas `order_items`: sin esto la orden
            // sincronizada quedaba invisible para el KDS y el pago por item.
            $this->orderController->materializeOrderItems($order, $items);

            $this->auditService->log('order.synced_offline', $actingUser, $order, [
                'order_id' => $order->id,
                'op_id' => $op['op_id'],
                'client_uuid' => $clientUuid,
                'occurred_at_client' => $clientAt->toIso8601String(),
                'offline' => true,
                'warnings' => $warnings,
                'total' => (float) $order->total,
            ], $request);

            return [
                'op_id' => $op['op_id'],
                'status' => $warnings ? 'warning' : 'created',
                'server_id' => $order->id,
                'total' => (float) $order->total,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Cobra una orden offline: crea un `PaymentReceipt` inmutable con
     * `client_uuid` (idempotente) y marca la orden `completed`. El total lo fija
     * el SERVIDOR (`order->total`), no el cliente (plan §4, §10). La propina va
     * separada (no suma a revenue ni a base gravable, CLAUDE §13).
     *
     * @param  array<string, mixed>  $op
     * @param  array<string, string>  $idMap
     * @return array<string, mixed>
     */
    private function applyOrderClose(array $op, string $companyNit, string $branchId, ?User $actingUser, Request $request, array $idMap): array
    {
        $payload = $op['payload'];

        // Resolver la orden: por server_id directo (orden creada online y cobrada
        // offline) o por client_uuid (orden creada offline) vía id_map del lote o BD.
        $orderId = $payload['order_id'] ?? null;
        if (! $orderId && ! empty($op['entity_ref'])) {
            $ref = $op['entity_ref'];
            $orderId = $idMap[$ref] ?? Order::query()->where('company_nit', $companyNit)->where('client_uuid', $ref)->value('id');
        }
        if (! $orderId) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'dependency_failed'];
        }

        $receiptUuid = $payload['client_uuid'] ?? null;
        if (! is_string($receiptUuid) || $receiptUuid === '') {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'missing_client_uuid'];
        }

        $method = $payload['payment_method'] ?? null;
        if (! in_array($method, ['cash', 'card', 'transfer'], true)) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'invalid_payment_method'];
        }

        // Resuelta en vivo (pudo abrirse por un cash.open del mismo lote).
        // Multi-caja: el cobro se imputa a LA caja que envió el cliente
        // (`payload.cash_session_id`), no a la primera abierta de la sede.
        $session = $this->resolveSyncCashSession($companyNit, $branchId, $payload['cash_session_id'] ?? null);
        if ($session === null) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'no_open_cash_session'];
        }

        return DB::transaction(function () use ($op, $payload, $orderId, $receiptUuid, $method, $companyNit, $session, $actingUser, $request) {
            /** @var Order|null $order */
            $order = Order::query()->where('company_nit', $companyNit)->lockForUpdate()->find($orderId);
            if ($order === null) {
                return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'order_not_found'];
            }

            // Idempotencia del receipt por su client_uuid: reintento → duplicate.
            $existingReceipt = PaymentReceipt::query()->where('client_uuid', $receiptUuid)->lockForUpdate()->first();
            if ($existingReceipt !== null) {
                return [
                    'op_id' => $op['op_id'],
                    'status' => 'duplicate',
                    'server_id' => $order->id,
                    'receipt_id' => $existingReceipt->id,
                    'amount' => (float) $existingReceipt->amount,
                ];
            }

            // Doble-cobro: la orden ya está cerrada con un cobro real (no nuestro).
            // Nunca creamos un segundo asiento de cobro — revisión manual.
            $terminalSuccess = config('orders.terminal_success');
            $alreadyPaid = $order->receipts()->where('payment_method', '!=', 'refund')->exists();
            if ($alreadyPaid || in_array($order->status, $terminalSuccess, true)) {
                return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'already_paid', 'server_id' => $order->id];
            }

            $total = (float) $order->total;
            // Paridad con closeWithPayment (F2): si el dispositivo no manda
            // tip_amount, se CONSERVA la propina que dejó el cliente en el
            // checkout público — el replay offline la pisaba con 0.
            $tip = array_key_exists('tip_amount', $payload) && $payload['tip_amount'] !== null
                ? round((float) $payload['tip_amount'], 2)
                : round((float) $order->tip_amount, 2);
            $expectedTotal = round($total + $tip, 2);
            $paidAt = isset($payload['paid_at']) ? Carbon::parse($payload['paid_at']) : now();
            $occurredAtClient = isset($payload['occurred_at_client']) ? Carbon::parse($payload['occurred_at_client']) : $paidAt;

            $paymentData = [
                'method' => $method,
                'total' => $total,
                'tip_amount' => $tip,
                'expected_total' => $expectedTotal,
                'paid_at' => $paidAt->toIso8601String(),
                'occurred_at_client' => $occurredAtClient->toIso8601String(),
                'reference' => $payload['reference'] ?? null,
                'synced_offline' => true,
            ];

            // Efectivo: registramos lo recibido y la devuelta si vinieron. NO
            // rechazamos por monto insuficiente — la venta ya ocurrió físicamente
            // (plan §8: un conflicto nunca borra plata).
            if ($method === 'cash' && isset($payload['amount_received'])) {
                $paymentData['amount_received'] = (float) $payload['amount_received'];
                $paymentData['change_returned'] = round((float) $payload['amount_received'] - $expectedTotal, 2);
            }

            $receipt = PaymentReceipt::create([
                'order_id' => $order->id,
                'company_nit' => $companyNit,
                'branch_id' => $order->branch_id,
                'client_uuid' => $receiptUuid,
                'file_path' => null,
                'payment_method' => $method,
                'amount' => $total,
                'reference' => $payload['reference'] ?? null,
                'paid_at' => $paidAt,
                'occurred_at_client' => $occurredAtClient,
                'cash_session_id' => $session->id,
                'payment_data' => $paymentData,
            ]);

            $order->tip_amount = $tip;
            $order->status = 'completed';
            $order->save();

            // Vender completado descuenta stock: una orden offline cobrada sin
            // pasar por cocina también produjo los platos (idempotente vía
            // inventory_consumed_at; nunca bloquea el cierre).
            $this->inventoryService->consumeForOrderOnce($order, $actingUser, 'sync.order_close');

            // KDS: items aún abiertos pasan a `served` para salir del tablero.
            OrderItem::query()
                ->where('order_id', $order->id)
                ->whereIn('status', (array) config('orders.item_statuses.operational'))
                ->update(['status' => 'served', 'served_at' => now()]);

            // El receipt cubre el total: stampear pago en los items para que el
            // cobro de mesa no los siga viendo como pendientes (doble cobro).
            $this->orderController->markOrderItemsPaid($order, $receipt->id, $paidAt);

            $this->auditService->log('order.closed_with_payment', $actingUser, $order, [
                'order_id' => $order->id,
                'op_id' => $op['op_id'],
                'method' => $method,
                'amount' => $total,
                'tip_amount' => $tip,
                'reference' => $payload['reference'] ?? null,
                'occurred_at_client' => $occurredAtClient->toIso8601String(),
                'offline' => true,
            ], $request);

            return [
                'op_id' => $op['op_id'],
                'status' => 'created',
                'server_id' => $order->id,
                'amount' => $total,
                'receipt_created' => true,
            ];
        });
    }

    /**
     * Abre la caja offline idempotente por `client_uuid`. Si ya hay otra sesión
     * abierta en la sede (otra terminal/dispositivo), NO abre una segunda:
     * devuelve `session_already_open` con su id para que las ventas se reimputen
     * a esa sesión (plan §8/§9).
     *
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function applyCashOpen(array $op, string $companyNit, string $branchId, ?User $actingUser): array
    {
        if ($actingUser === null) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'no_actor'];
        }
        $payload = $op['payload'];
        $clientUuid = $payload['client_uuid'] ?? null;
        if (! is_string($clientUuid) || $clientUuid === '') {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'missing_client_uuid'];
        }

        // Idempotencia: esta apertura ya se aplicó → duplicate.
        $existing = $this->cashRegister->sessionByClientUuid($companyNit, $branchId, $clientUuid);
        if ($existing !== null) {
            return ['op_id' => $op['op_id'], 'status' => 'duplicate', 'server_id' => $existing->id];
        }

        // Multi-caja: el cliente indica QUÉ caja abre. El conflicto
        // "ya hay sesión abierta" se evalúa por CAJA cuando viene el id —
        // evaluarlo por sede impedía abrir la caja 2 offline con la caja 1
        // abierta. Sin id (cliente legacy / mono-caja) se conserva el chequeo
        // por sede.
        $cashRegisterId = isset($payload['cash_register_id']) && is_string($payload['cash_register_id']) && $payload['cash_register_id'] !== ''
            ? $payload['cash_register_id']
            : null;

        $open = $cashRegisterId !== null
            ? CashRegisterSession::query()->where('cash_register_id', $cashRegisterId)->where('status', 'open')->first()
            : $this->cashRegister->activeSessionForBranch($companyNit, $branchId);
        if ($open !== null) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'session_already_open', 'server_id' => $open->id];
        }

        $openedAtClient = isset($op['created_at_client']) ? Carbon::parse($op['created_at_client']) : null;

        try {
            $session = $this->cashRegister->openSession(
                $companyNit,
                $branchId,
                $actingUser,
                (float) ($payload['opening_amount'] ?? 0),
                $payload['notes'] ?? null,
                $clientUuid,
                $openedAtClient,
                $cashRegisterId,
            );
        } catch (ValidationException) {
            // Caja inexistente/archivada en esta sede, o carrera con otra
            // apertura concurrente de la misma caja.
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'invalid_cash_register'];
        }

        return ['op_id' => $op['op_id'], 'status' => 'created', 'server_id' => $session->id];
    }

    /**
     * Registra un egreso offline idempotente por `client_uuid` contra la sesión
     * abierta de la sede.
     *
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function applyCashExpense(array $op, string $companyNit, string $branchId, ?User $actingUser): array
    {
        if ($actingUser === null) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'no_actor'];
        }
        $payload = $op['payload'];
        $clientUuid = $payload['client_uuid'] ?? null;
        if (! is_string($clientUuid) || $clientUuid === '') {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'missing_client_uuid'];
        }

        $session = $this->resolveSyncCashSession($companyNit, $branchId, $payload['cash_session_id'] ?? null);
        if ($session === null) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'no_open_cash_session'];
        }

        $occurredAtClient = isset($payload['occurred_at_client']) ? Carbon::parse($payload['occurred_at_client']) : null;
        $expense = $this->cashRegister->recordExpense(
            $session,
            $actingUser,
            (float) ($payload['amount'] ?? 0),
            (string) ($payload['category'] ?? ''),
            $payload['description'] ?? null,
            (string) ($payload['payment_method'] ?? 'cash'),
            $clientUuid,
            $occurredAtClient,
        );

        return ['op_id' => $op['op_id'], 'status' => 'created', 'server_id' => $expense->id, 'amount' => (float) $expense->amount];
    }

    /**
     * Entrada de efectivo offline (aporte/préstamo/ajuste). Espejo de
     * applyCashExpense: idempotente por client_uuid, exige sesión abierta.
     *
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function applyCashIncome(array $op, string $companyNit, string $branchId, ?User $actingUser): array
    {
        if ($actingUser === null) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'no_actor'];
        }
        $payload = $op['payload'];
        $clientUuid = $payload['client_uuid'] ?? null;
        if (! is_string($clientUuid) || $clientUuid === '') {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'missing_client_uuid'];
        }

        $session = $this->resolveSyncCashSession($companyNit, $branchId, $payload['cash_session_id'] ?? null);
        if ($session === null) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'no_open_cash_session'];
        }

        $occurredAtClient = isset($payload['occurred_at_client']) ? Carbon::parse($payload['occurred_at_client']) : null;
        $income = $this->cashRegister->recordIncome(
            $session,
            $actingUser,
            (float) ($payload['amount'] ?? 0),
            (string) ($payload['category'] ?? ''),
            $payload['description'] ?? null,
            (string) ($payload['payment_method'] ?? 'cash'),
            $clientUuid,
            $occurredAtClient,
        );

        return ['op_id' => $op['op_id'], 'status' => 'created', 'server_id' => $income->id, 'amount' => (float) $income->amount];
    }

    /**
     * Cierre provisional offline: el server recalcula `expected_cash` desde los
     * receipts ya aplicados; el `closing_amount` (conteo físico) lo capturó el
     * cajero. Idempotente: si ya está cerrada (re-sync) → duplicate.
     *
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function applyCashClose(array $op, string $companyNit, string $branchId, ?User $actingUser, Request $request): array
    {
        if ($actingUser === null) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'no_actor'];
        }
        $payload = $op['payload'];
        $closedAtClient = isset($payload['closed_at_client']) ? Carbon::parse($payload['closed_at_client']) : null;

        // Multi-caja: cerrar LA caja que el cajero operaba, no la
        // primera abierta de la sede — con 2+ cajas abiertas el cierre offline
        // caía en la sesión equivocada con el conteo físico de otra,
        // descuadrando ambas. Si el id apunta a una sesión ya cerrada
        // (re-sync), es duplicate directo.
        $requestedSessionId = isset($payload['cash_session_id']) && is_string($payload['cash_session_id']) && $payload['cash_session_id'] !== ''
            ? $payload['cash_session_id']
            : null;

        $targetSessionId = null;
        if ($requestedSessionId !== null) {
            $target = CashRegisterSession::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
                ->where('id', $requestedSessionId)
                ->first()
                ?? $this->cashRegister->sessionByClientUuid($companyNit, $branchId, $requestedSessionId);

            if ($target !== null && $target->status !== 'open') {
                return ['op_id' => $op['op_id'], 'status' => 'duplicate', 'server_id' => $target->id];
            }
            // Si no se pudo mapear (id provisional de una apertura que terminó
            // en conflicto), cae al comportamiento legacy (única abierta).
            $targetSessionId = $target?->id;
        }

        $outcome = $this->cashRegister->closeSessionFromSync(
            $companyNit,
            $branchId,
            $actingUser,
            (float) ($payload['closing_amount'] ?? 0),
            $payload['notes'] ?? null,
            $closedAtClient,
            $targetSessionId,
        );

        $session = $outcome['session'];
        if ($session === null) {
            return ['op_id' => $op['op_id'], 'status' => 'conflict', 'code' => 'no_session_to_close'];
        }

        if ($outcome['status'] === 'closed') {
            $warnings = [];
            $clientClose = (float) ($payload['closing_amount'] ?? 0);
            if (abs($clientClose - (float) $session->expected_cash) > 0.009) {
                // Diferencia entre conteo físico y esperado recomputado: revisión.
                $warnings[] = ['type' => 'cash_close_recomputed', 'expected_cash' => (float) $session->expected_cash, 'closing_amount' => $clientClose];
            }

            $this->auditService->log('cash_register.closed_offline', $actingUser, $session, [
                'session_id' => $session->id,
                'op_id' => $op['op_id'],
                'closing_amount' => $clientClose,
                'expected_cash' => (float) $session->expected_cash,
                'cash_difference' => (float) $session->cash_difference,
                'offline' => true,
            ], $request);

            return ['op_id' => $op['op_id'], 'status' => $warnings ? 'warning' : 'created', 'server_id' => $session->id, 'warnings' => $warnings];
        }

        return ['op_id' => $op['op_id'], 'status' => 'duplicate', 'server_id' => $session->id];
    }

    /**
     * Resuelve la sesión de caja destino de un egreso/ingreso offline.
     *
     * El cliente envía `cash_session_id`, que puede ser el id real de la
     * sesión O el client_uuid provisional de una apertura offline aún sin
     * mapear. Sin id (cliente legacy) o si no mapea, cae a la única sesión
     * abierta de la sede; con varias abiertas y sin poder resolver, null →
     * conflict (imputarlo a "la primera" descuadraba la caja equivocada).
     */
    private function resolveSyncCashSession(string $companyNit, string $branchId, mixed $cashSessionId): ?CashRegisterSession
    {
        if (is_string($cashSessionId) && $cashSessionId !== '') {
            $byId = CashRegisterSession::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
                ->where('id', $cashSessionId)
                ->where('status', 'open')
                ->first();
            if ($byId !== null) {
                return $byId;
            }

            $byClient = $this->cashRegister->sessionByClientUuid($companyNit, $branchId, $cashSessionId);
            if ($byClient !== null && $byClient->status === 'open') {
                return $byClient;
            }
        }

        $open = $this->cashRegister->openSessionsForBranch($companyNit, $branchId);

        return $open->count() === 1 ? $open->first() : null;
    }

    /**
     * @param  array{count: int, amount: float}  $orders
     * @param  array{count: int, amount: float}  $receipts
     */
    private function recordEvents(string $companyNit, string $branchId, ?string $userId, array $orders, array $receipts, int $failed): void
    {
        $now = now();
        $rows = [];
        if ($orders['count'] > 0) {
            $rows[] = ['event_type' => 'order_synced', 'count' => $orders['count'], 'amount' => round($orders['amount'], 2)];
        }
        if ($receipts['count'] > 0) {
            $rows[] = ['event_type' => 'receipt_synced', 'count' => $receipts['count'], 'amount' => round($receipts['amount'], 2)];
        }
        if ($failed > 0) {
            $rows[] = ['event_type' => 'sync_failed', 'count' => $failed, 'amount' => 0];
        }

        foreach ($rows as $row) {
            OfflineSyncEvent::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'event_type' => $row['event_type'],
                'count' => $row['count'],
                'total_amount' => $row['amount'],
                'occurred_at' => $now,
            ]);
        }
    }
}
