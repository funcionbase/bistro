<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\TableCashierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Caja con pago dividido por comensal — API.
 *
 * Requiere JWT + sede + permission `orders.update`. Devuelve JSON.
 * UI: `pages/caja/table-session.tsx`.
 */
class TableCashierController extends Controller
{
    public function __construct(private readonly TableCashierService $cashier) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);

        return response()->json(['data' => $this->cashier->getSessionState($session)]);
    }

    public function payPartial(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'guest_id' => ['nullable', 'uuid'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['uuid'],
            'payment_method' => ['required', 'string', 'in:cash,card,transfer,nequi,daviplata'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'client_uuid' => ['required', 'string', 'uuid'],
            'cash_session_id' => ['nullable', 'uuid'],
        ]);

        try {
            $receipt = $this->cashier->payPartial($session, $payload, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'receipt' => [
                'id' => $receipt->id,
                'payment_method' => $receipt->payment_method,
                'amount' => (string) $receipt->amount,
                'guest_id' => $receipt->guest_id,
                'paid_at' => optional($receipt->paid_at)?->toIso8601String(),
            ],
            'state' => $this->cashier->getSessionState($session->refresh()),
        ], 201);
    }

    public function payAll(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'payment_method' => ['required', 'string', 'in:cash,card,transfer,nequi,daviplata'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'client_uuid' => ['required', 'string', 'uuid'],
            'payer_guest_id' => ['nullable', 'uuid'],
            'cash_session_id' => ['nullable', 'uuid'],
        ]);

        try {
            $receipt = $this->cashier->payAll($session, $payload, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'receipt' => [
                'id' => $receipt->id,
                'payment_method' => $receipt->payment_method,
                'amount' => (string) $receipt->amount,
            ],
            'state' => $this->cashier->getSessionState($session->refresh()),
        ], 201);
    }

    public function refundItem(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);
        $user = $this->actor($request);

        $payload = $request->validate([
            'item_id' => ['required', 'uuid'],
            'payment_method' => ['required', 'string', 'in:cash,card,transfer'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference' => ['required', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'client_uuid' => ['required', 'string', 'uuid'],
            'cash_session_id' => ['nullable', 'uuid'],
        ]);

        try {
            $receipt = $this->cashier->refundItem($session, $payload, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'receipt' => [
                'id' => $receipt->id,
                'amount' => (string) $receipt->amount,
                'payment_method' => $receipt->payment_method,
            ],
            'state' => $this->cashier->getSessionState($session->refresh()),
        ], 201);
    }

    public function timeline(Request $request, string $id): JsonResponse
    {
        $session = $this->loadSession($request, $id);

        $orders = Order::query()
            ->withoutGlobalScopes()
            ->where('table_session_id', $session->id)
            ->get(['id', 'ordered_at', 'total', 'status']);

        $orderIds = $orders->pluck('id')->all();

        /** @var Collection<int, AuditLog> $logs */
        $logs = AuditLog::query()
            ->whereIn('action', ['kds.item.in_kitchen', 'kds.item.ready', 'order.status_changed', 'table.payment.split'])
            ->where(function ($q) use ($orderIds, $session) {
                $q->whereIn(\DB::raw("(data->>'order_id')"), $orderIds)
                    ->orWhere(\DB::raw("data->>'table_session_id'"), $session->id);
            })
            ->orderBy('created_at')
            ->get();

        $events = collect();

        // Apertura de sesión
        $events->push([
            'at' => $session->opened_at->toIso8601String(),
            'action' => 'session.opened',
            'label' => 'Mesa abierta',
            'detail' => null,
            'order_id' => null,
        ]);

        // Un evento por pedido (su timestamp de creación)
        foreach ($orders as $order) {
            $events->push([
                'at' => ($order->ordered_at ?? $order->created_at)->toIso8601String(),
                'action' => 'order.created',
                'label' => 'Pedido tomado',
                'detail' => '$'.number_format((float) $order->total, 0, ',', '.'),
                'order_id' => $order->id,
            ]);
        }

        // Eventos de audit_log — KDS deduplica: primero in_kitchen, último ready
        $kdsReady = [];

        foreach ($logs as $log) {
            $data = $log->data ?? [];
            $orderId = $data['order_id'] ?? null;

            switch ($log->action) {
                case 'kds.item.in_kitchen':
                    // Solo primer evento por orden
                    if (! $events->contains(fn ($e) => $e['action'] === 'kds.in_kitchen' && $e['order_id'] === $orderId)) {
                        $events->push([
                            'at' => $log->created_at->toIso8601String(),
                            'action' => 'kds.in_kitchen',
                            'label' => 'En cocina',
                            'detail' => null,
                            'order_id' => $orderId,
                        ]);
                    }
                    break;

                case 'kds.item.ready':
                    // Sobreescribir con el más reciente
                    $kdsReady[$orderId ?? ''] = [
                        'at' => $log->created_at->toIso8601String(),
                        'action' => 'kds.ready',
                        'label' => 'Último ítem listo',
                        'detail' => null,
                        'order_id' => $orderId,
                    ];
                    break;

                case 'order.status_changed':
                    $to = $data['to'] ?? '';
                    $labelMap = [
                        'approved' => 'Aprobado',
                        'completed' => 'Completado',
                        'cancelled' => 'Cancelado',
                        'refunded' => 'Devuelto',
                        'in_transit' => 'En camino',
                    ];
                    if (isset($labelMap[$to])) {
                        $events->push([
                            'at' => $log->created_at->toIso8601String(),
                            'action' => 'order.status.'.$to,
                            'label' => $labelMap[$to],
                            'detail' => null,
                            'order_id' => $orderId,
                        ]);
                    }
                    break;

                case 'table.payment.split':
                    $amount = (int) ($data['amount'] ?? 0);
                    $method = $data['payment_method'] ?? '';
                    $itemsPaid = (int) ($data['items_paid'] ?? 0);
                    $tip = (int) ($data['tip_amount'] ?? 0);
                    $detail = '$'.number_format($amount, 0, ',', '.').' · '.$method;
                    if ($tip > 0) {
                        $detail .= ' · propina $'.number_format($tip, 0, ',', '.');
                    }
                    $events->push([
                        'at' => $log->created_at->toIso8601String(),
                        'action' => 'payment.split',
                        'label' => $itemsPaid === 1 ? 'Cobro (1 ítem)' : "Cobro ({$itemsPaid} ítems)",
                        'detail' => $detail,
                        'order_id' => $orderId,
                    ]);
                    break;
            }
        }

        foreach ($kdsReady as $event) {
            $events->push($event);
        }

        // Cierre de sesión
        if ($session->closed_at) {
            $events->push([
                'at' => $session->closed_at->toIso8601String(),
                'action' => 'session.closed',
                'label' => 'Mesa cerrada',
                'detail' => null,
                'order_id' => null,
            ]);
        }

        // Ordenar cronológicamente y calcular duración desde evento previo
        $sorted = $events->sortBy('at')->values();

        $result = $sorted->map(function (array $event, int $i) use ($sorted): array {
            $prev = $i > 0 ? $sorted[$i - 1] : null;
            $durationSeconds = $prev
                ? (int) (strtotime($event['at']) - strtotime($prev['at']))
                : null;

            return array_merge($event, ['duration_seconds' => $durationSeconds]);
        });

        return response()->json(['data' => $result->values()]);
    }

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
}
