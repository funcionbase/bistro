<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Events\OrderItemSubmittedForApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreBranchOrderRequest;
use App\Models\Branch;
use App\Models\CartSession;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantMenu;
use App\Services\AuditService;
use App\Services\BranchSettingsService;
use App\Services\BusinessHoursService;
use App\Services\CashRegisterService;
use App\Services\TableSessionService;
use App\Support\OrderTotalCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pedido público SIN mesa desde el QR de menú de sede (`/menus?branch={token}`):
 * para llevar o domicilio. La orden nace `pending_approval` y cae a caja, donde
 * el staff valida los datos (dirección incluida) y la aprueba o la rechaza.
 *
 * Reglas contables (CLAUDE.md §13):
 *  - `unit_price` SIEMPRE del menú activo de la sede — nunca del payload.
 *  - Creación envuelta en `DB::transaction`; totales via `OrderTotalCalculator`.
 *  - El costo del domicilio entra como línea "Domicilio" (tax 0) para conservar
 *    el invariante `orders.total = SUM(líneas)` en receipts/reportes/DIAN.
 */
class BranchOrderController extends Controller
{
    /** menu_item_id sintético de la línea de domicilio (no existe en el menú). Alias del canónico en Order. */
    public const DELIVERY_FEE_ITEM_ID = Order::DELIVERY_FEE_ITEM_ID;

    public function __construct(
        private readonly AuditService $audit,
        private readonly OrderTotalCalculator $totals,
        private readonly BusinessHoursService $businessHours,
        private readonly CashRegisterService $cashRegister,
        private readonly BranchSettingsService $branchSettings,
        private readonly TableSessionService $tableSessions,
    ) {}

    public function store(StoreBranchOrderRequest $request, string $menuQrToken): JsonResponse
    {
        $branch = Branch::query()
            ->where('menu_qr_token', $menuQrToken)
            ->whereNull('archived_at')
            ->first();

        if ($branch === null) {
            return response()->json(['message' => 'La sede no existe o no está disponible.'], 404);
        }

        $company = Company::query()->where('nit', $branch->company_nit)->first();
        if ($company === null || ! $company->canServePublic()) {
            return response()->json(['message' => 'La empresa no está disponible en este momento.'], 404);
        }

        // Mismas puertas que el menú público (showPublic): horario + caja
        // abierta. Sin caja activa el cobro posterior fallaría.
        $hours = $this->businessHours->getCurrentStatus($company->nit, null, (string) $branch->id);
        if (! $hours['menu_available']) {
            return response()->json(['message' => 'La empresa está cerrada en este momento.'], 423);
        }
        if (! $this->cashRegister->activeSessionForBranch($company->nit, (string) $branch->id)) {
            return response()->json(['message' => 'La empresa no está recibiendo pedidos en este momento.'], 423);
        }

        $validated = $request->validated();

        try {
            $phone = $this->tableSessions->normalizePhone($validated['customer_phone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['customer_phone' => [$e->getMessage()]],
            ], 422);
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

        $isDelivery = $validated['type'] === 'delivery';
        $deliveryFee = $isDelivery
            ? round((float) ($this->branchSettings->get((string) $branch->id, 'delivery_fee') ?? 0), 2)
            : 0.0;

        try {
            $order = DB::transaction(function () use ($validated, $branch, $company, $menu, $phone, $isDelivery, $deliveryFee, $request) {
                $order = new Order;
                $order->company_nit = $company->nit;
                $order->branch_id = $branch->id;
                $order->status = 'pending_approval';
                $order->order_type = $validated['type'];
                $order->client_phone = $phone;
                // La ciudad del domicilio es la de la sede (regla del negocio: una
                // sede solo entrega en su ciudad). Se anexa a la dirección para que
                // quede completa sin pedírsela al cliente.
                $order->delivery_address = $isDelivery
                    ? trim($validated['address']).' — Barrio '.trim($validated['neighborhood']).($branch->city ? ' — '.$branch->city : '')
                    : null;
                $order->total = '0.00';
                $order->subtotal = '0.00';
                $order->ordered_at = Carbon::now();

                // Snapshot tributario al nacer (paridad con caja y flujo QR de mesa).
                $order->snapshot_default_tax_rate = (float) $company->default_tax_rate;
                $order->tax_regime = $company->tax_regime;
                $order->tax_included_in_price = (bool) $company->tax_included_in_price;
                $order->save();

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
                    $item->notes = $this->sanitizeNotes($line['notes'] ?? null);
                    $item->status = 'pending_approval';
                    $item->submitted_at = $now;
                    $item->save();

                    $firstItem ??= $item;
                }

                if ($deliveryFee > 0) {
                    $fee = new OrderItem;
                    $fee->order_id = $order->id;
                    $fee->menu_item_id = self::DELIVERY_FEE_ITEM_ID;
                    $fee->name = 'Domicilio';
                    $fee->unit_price = (string) number_format($deliveryFee, 2, '.', '');
                    // Tasa explícita 0: el transporte no hace parte de la base
                    // gravable del menú; sin esto heredaría el default de la empresa.
                    $fee->tax_rate = 0.0;
                    $fee->quantity = 1;
                    $fee->category = 'domicilio';
                    // El fee NO es un plato: nace `served` (consumable — sigue
                    // contando al total) para no aparecer como ticket cocinable
                    // en el KDS ni bloquear la promoción a `ready`, que exige
                    // todos los items consumibles en ready|served.
                    $fee->status = 'served';
                    $fee->submitted_at = $now;
                    $fee->served_at = $now;
                    $fee->save();
                }

                $this->totals->recalculateAndSave($order->refresh());

                $this->upsertContact($company->nit, (string) $branch->id, $validated['customer_name'], $phone);

                $this->audit->log('order.created_by_customer', user: null, auditable: $order, data: [
                    'company_nit' => $company->nit,
                    'branch_id' => (string) $branch->id,
                    'order_type' => $validated['type'],
                    'client_phone' => $phone,
                    'items_count' => count($validated['items']),
                    'delivery_fee' => $deliveryFee,
                    'total' => (string) $order->total,
                ], request: $request);

                // Push a staff con orders.update — mismo evento del flujo QR de mesa.
                if ($firstItem !== null) {
                    event(new OrderItemSubmittedForApproval($firstItem));
                }

                return $order;
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['items' => [$e->getMessage()]],
            ], 422);
        }

        // Fuera de la transacción: si el vínculo con el chat falla, la orden
        // ya creada NO se rompe (el cliente no debe ver un error por esto).
        $this->linkCartSession($order, $validated['cart_token'] ?? null, $company->nit);

        return response()->json([
            'data' => [
                'order_id' => (string) $order->id,
                'status' => $order->status,
                'order_type' => $order->order_type,
                'total' => (string) $order->total,
                'delivery_fee' => number_format($deliveryFee, 2, '.', ''),
            ],
        ], 201);
    }

    /**
     * Convierte la sesión de carta enviada desde /chats (`/menus?cart={uuid}`)
     * y precarga en la conversación lo que el cliente seleccionó: marca la
     * `CartSession` como `converted` con `order_id` y crea un `ChatMessage`
     * (sender bot, solo visible en el hilo — sin outbound) con el resumen del
     * pedido, para que el operador lo vea llegar en el chat que envió la carta.
     */
    private function linkCartSession(Order $order, ?string $token, string $companyNit): void
    {
        if ($token === null || $token === '') {
            return;
        }

        try {
            // Escape del BranchScope: contexto público sin JWT. company_nit
            // ancla la sesión a la empresa de la sede del pedido.
            $session = CartSession::withoutGlobalScopes()
                ->where('jwt_jti', $token)
                ->where('company_nit', $companyNit)
                ->where('status', 'active')
                ->where('expired_at', '>', now())
                ->first();

            if ($session === null) {
                return;
            }

            $session->status = 'converted';
            $session->order_id = $order->id;
            $session->save();

            if ($session->chat_id === null) {
                return;
            }

            $chat = Chat::withoutBranchScope()->whereKey($session->chat_id)->first();
            if ($chat === null) {
                return;
            }

            $lines = $order->orderItems()
                ->orderBy('created_at')
                ->get()
                ->map(fn (OrderItem $i): string => "{$i->quantity}× {$i->name}")
                ->implode("\n");

            $typeLabel = $order->order_type === 'delivery' ? 'domicilio' : 'para recoger';
            $total = '$'.number_format((float) $order->total, 0, ',', '.');

            $message = new ChatMessage;
            $message->chat_id = $chat->id;
            $message->sender = 'bot';
            $message->body = "🛒 Pedido desde la carta ({$typeLabel}):\n{$lines}\nTotal: {$total} — pendiente de aprobación.";
            $message->status = 'sent';
            $message->sent_at = now();
            $message->save();

            $chat->last_message_at = now();
            $chat->save();
        } catch (\Throwable $e) {
            Log::warning('cart_session.link_failed', [
                'order_id' => $order->id,
                'cart_token' => $token,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Espejo mínimo de `TableSessionService::upsertContact` (privado allá).
     * `$phone` ya llega canónico (`57XXXXXXXXXX`, normalizePhone); se tolera la
     * variante legacy de 10 dígitos por si quedó alguna fila sin migrar.
     */
    private function upsertContact(string $companyNit, string $branchId, string $name, string $phone): void
    {
        $contact = Contact::withoutBranchScope()
            ->where('company_nit', $companyNit)
            ->whereIn('phone', array_unique([$phone, str_starts_with($phone, '57') ? substr($phone, 2) : $phone]))
            ->first();

        if ($contact === null) {
            $contact = new Contact;
            $contact->company_nit = $companyNit;
            $contact->branch_id = $branchId;
            $contact->phone = $phone;
            $contact->name = $name;
            $contact->save();

            return;
        }

        if (trim((string) $contact->name) === '' && trim($name) !== '') {
            $contact->name = $name;
            $contact->save();
        }
    }

    /** Trim + strip tags + tope 500, igual que el flujo QR de mesa. */
    private function sanitizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }
        $clean = trim(strip_tags($notes));

        return $clean === '' ? null : mb_substr($clean, 0, 500);
    }
}
