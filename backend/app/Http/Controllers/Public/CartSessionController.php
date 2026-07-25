<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Events\OrderItemSubmittedForApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\AppendCartOrderItemsRequest;
use App\Models\Branch;
use App\Models\CartSession;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantMenu;
use App\Services\AuditService;
use App\Services\BusinessHoursService;
use App\Services\CashRegisterService;
use App\Support\OrderTotalCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints públicos de la sesión de carta (`/menus?cart={uuid}`,
 * plan-mejoras-chat F3): tracking de actividad, estado de las órdenes del
 * carrito y append de items del cliente mientras la orden sigue
 * `pending_approval`.
 *
 * Bearer = token UUID del cart (jwt_jti UNIQUE, no enumerable). Sin RBAC:
 * `CartSession` ancla company_nit + branch_id y el append valida la
 * pertenencia orden↔sesión. Throttle `cart-public`.
 */
class CartSessionController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OrderTotalCalculator $totals,
        private readonly BusinessHoursService $businessHours,
        private readonly CashRegisterService $cashRegister,
    ) {}

    /**
     * Ping de actividad del cliente en la carta ("está armando el pedido").
     * SIEMPRE 204 — no filtra existencia de tokens.
     */
    public function activity(string $token): Response
    {
        $session = $this->resolveSession($token);

        if ($session !== null && $session->status === 'active' && ! $session->expired_at->isPast()) {
            $session->last_activity_at = now();
            $session->viewed_at ??= now();
            $session->save();
        }

        return response()->noContent();
    }

    /**
     * Todas las órdenes de la sesión con estado derivado para el cliente
     * (CA6). Funciona aunque la sesión esté convertida o expirada — el
     * cliente sigue el estado de su pedido con el mismo link.
     */
    public function orders(string $token): JsonResponse
    {
        $session = $this->resolveSession($token);
        if ($session === null) {
            return response()->json(['message' => 'La sesión no existe.'], 404);
        }

        $orders = Order::withoutGlobalScopes()
            ->where('cart_session_id', $session->id)
            ->orderBy('ordered_at')
            ->get()
            ->map(fn (Order $order): array => [
                'id' => (string) $order->id,
                'short_code' => strtoupper(substr((string) $order->id, 0, 4)),
                'status' => $order->status,
                'status_label' => $this->publicStatusLabel($order),
                'order_type' => $order->order_type,
                'total' => (string) $order->total,
                'tip_amount' => (string) $order->tip_amount,
                'payment_preference' => $order->payment_preference,
                'ordered_at' => $order->ordered_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['data' => $orders]);
    }

    /**
     * Append de items del cliente a su orden mientras siga `pending_approval`
     * (caso borde multi-orden). Si el cajero la aprobó en paralelo, 409
     * `ORDER_ALREADY_APPROVED` y el frontend cae a "crear pedido nuevo".
     */
    public function appendItems(AppendCartOrderItemsRequest $request, string $token, string $orderId): JsonResponse
    {
        $session = $this->resolveSession($token);
        if ($session === null || $session->expired_at->isPast()) {
            return response()->json(['message' => 'La sesión no existe o expiró.'], 404);
        }

        $company = Company::query()->where('nit', $session->company_nit)->first();
        $branch = Branch::query()->whereKey($session->branch_id)->whereNull('archived_at')->first();
        if ($company === null || $branch === null || ! $company->canServePublic()) {
            return response()->json(['message' => 'La empresa no está disponible en este momento.'], 404);
        }

        // Mismas puertas que el pedido público: horario + caja abierta.
        $hours = $this->businessHours->getCurrentStatus($company->nit, null, (string) $branch->id);
        if (! $hours['menu_available'] || ! $this->cashRegister->activeSessionForBranch($company->nit, (string) $branch->id)) {
            return response()->json(['message' => 'La empresa no está recibiendo pedidos en este momento.'], 423);
        }

        $menu = RestaurantMenu::query()
            ->withoutGlobalScopes()
            ->forCompany($company->nit)
            ->where('branch_id', $branch->id)
            ->active()
            ->orderByDesc('updated_at')
            ->first();

        if ($menu === null) {
            return response()->json(['message' => 'La sede no tiene un menú activo en este momento.'], 422);
        }

        $validated = $request->validated();

        try {
            $order = DB::transaction(function () use ($validated, $session, $menu, $orderId) {
                /** @var Order|null $order */
                $order = Order::withoutGlobalScopes()
                    ->where('cart_session_id', $session->id)
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->first();

                if ($order === null) {
                    throw new \RuntimeException('ORDER_NOT_FOUND');
                }

                // Re-chequeo DENTRO de la txn: si el cajero aprobó en paralelo,
                // la orden ya no se puede modificar desde la web.
                if ($order->status !== 'pending_approval') {
                    throw new \RuntimeException('ORDER_ALREADY_APPROVED');
                }

                $now = Carbon::now();
                $firstItem = null;

                foreach ($validated['items'] as $line) {
                    $menuItem = $menu->findMenuItem((string) $line['id']);
                    if ($menuItem === null || ($menuItem['available'] ?? true) !== true) {
                        throw new \InvalidArgumentException('Uno de los platos seleccionados ya no está disponible.');
                    }

                    $item = new OrderItem;
                    $item->order_id = $order->id;
                    $item->menu_item_id = (string) $menuItem['id'];
                    $item->name = (string) $menuItem['name'];
                    $item->unit_price = (string) number_format((float) ($menuItem['price'] ?? 0), 2, '.', '');
                    $item->unit_cost = isset($menuItem['cost']) ? (string) number_format((float) $menuItem['cost'], 2, '.', '') : null;
                    $item->tax_rate = isset($menuItem['tax_rate']) && $menuItem['tax_rate'] !== null ? (float) $menuItem['tax_rate'] : null;
                    $item->quantity = (int) $line['quantity'];
                    $item->category = isset($menuItem['category']) ? (string) $menuItem['category'] : null;
                    $item->notes = isset($line['notes']) && trim((string) $line['notes']) !== ''
                        ? mb_substr(trim(strip_tags((string) $line['notes'])), 0, 500)
                        : null;
                    $item->status = 'pending_approval';
                    $item->submitted_at = $now;
                    $item->save();

                    $firstItem ??= $item;
                }

                // Nunca se agrega otra línea de domicilio: los ids se resuelven
                // contra el menú, donde delivery_fee no existe.
                $this->totals->recalculateAndSave($order->refresh());

                $this->audit->log('order.items_appended_by_customer', user: null, auditable: $order, data: [
                    'company_nit' => $order->company_nit,
                    'cart_session_id' => (string) $session->id,
                    'items_count' => count($validated['items']),
                    'total' => (string) $order->total,
                ]);

                if ($firstItem !== null) {
                    event(new OrderItemSubmittedForApproval($firstItem));
                }

                return $order;
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'ORDER_ALREADY_APPROVED') {
                return response()->json([
                    'message' => 'El pedido ya fue aprobado — crea un pedido nuevo con estos productos.',
                    'code' => 'ORDER_ALREADY_APPROVED',
                ], 409);
            }
            if ($e->getMessage() === 'ORDER_NOT_FOUND') {
                return response()->json(['message' => 'El pedido no existe.'], 404);
            }
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['items' => [$e->getMessage()]],
            ], 422);
        }

        $this->notifyChatAppend($session, $order, count($validated['items']));

        return response()->json([
            'data' => [
                'order_id' => (string) $order->id,
                'status' => $order->status,
                'total' => (string) $order->total,
            ],
        ]);
    }

    /** Escape del BranchScope: contexto público sin JWT, bearer = jwt_jti UNIQUE. */
    private function resolveSession(string $token): ?CartSession
    {
        return CartSession::withoutGlobalScopes()->where('jwt_jti', $token)->first();
    }

    /**
     * Estado derivado para el cliente: `pending_approval` se matiza según el
     * medio de pago elegido (transferencias esperan comprobante).
     */
    private function publicStatusLabel(Order $order): string
    {
        if ($order->status === 'pending_approval') {
            return in_array($order->payment_preference, ['transfer', 'nequi', 'daviplata'], true)
                ? 'Esperando comprobante'
                : 'En revisión';
        }

        return (string) (config('orders.labels')[$order->status] ?? $order->status);
    }

    /**
     * ChatMessage interno (bot, sin outbound) para que el operador vea el
     * append llegar al hilo — patrón `BranchOrderController::linkCartSession`.
     * Nunca rompe el append si falla.
     */
    private function notifyChatAppend(CartSession $session, Order $order, int $itemsCount): void
    {
        try {
            if ($session->chat_id === null) {
                return;
            }

            $chat = Chat::withoutBranchScope()->whereKey($session->chat_id)->first();
            if ($chat === null) {
                return;
            }

            $total = '$'.number_format((float) $order->total, 0, ',', '.');

            $message = new ChatMessage;
            $message->chat_id = $chat->id;
            $message->sender = 'bot';
            $message->body = "🛒 El cliente agregó {$itemsCount} ".($itemsCount === 1 ? 'producto' : 'productos')." a su pedido.\nTotal nuevo: {$total} — pendiente de aprobación.";
            $message->status = 'sent';
            $message->sent_at = now();
            $message->save();

            $chat->last_message_at = now();
            $chat->save();
        } catch (\Throwable $e) {
            Log::warning('cart_session.append_notify_failed', [
                'order_id' => $order->id,
                'chat_id' => $session->chat_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
