<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\PaymentReceipt;
use App\Models\RestaurantMenu;
use App\Models\TableSession;
use App\Models\TableSessionGuest;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\CashRegisterService;
use App\Services\CouponService;
use App\Services\CrmService;
use App\Services\FeaturePermissionService;
use App\Services\InventoryService;
use App\Services\LoyaltyService;
use App\Services\RecipeCostService;
use App\Services\Sms\OrderStatusSmsDispatcher;
use App\Services\TableSessionService;
use App\Services\TaxCalculator;
use App\Support\OrderTotalCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Expone las órdenes activas del restaurante para el tablero kanban.
 *
 * index()        — retorna órdenes del kanban (config('orders.kanban')).
 * store()        — crea una orden desde caja validando los ítems contra el menú activo del día.
 * updateStatus() — avanza o corrige el estado de una orden; valida que pertenezca a la empresa activa.
 */
class OrderController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly AuditService $auditService,
        private readonly TaxCalculator $taxCalculator,
        private readonly CouponService $couponService,
        private readonly CashRegisterService $cashRegister,
        private readonly InventoryService $inventoryService,
        private readonly LoyaltyService $loyaltyService,
        private readonly RecipeCostService $recipeCostService,
        private readonly OrderStatusSmsDispatcher $smsDispatcher,
        private readonly OrderTotalCalculator $orderTotals,
    ) {}

    /**
     * Costo unitario a snapshotear en la línea de la orden. Prioriza el costo
     * manual del menú; si no existe, cae al costo computado desde la receta
     * (BOM) para que el food cost refleje las recetas configuradas. Devuelve
     * null si no hay ninguno (no se asume 0 para no falsear el food cost).
     *
     * @param  array<string, mixed>  $catalogItem
     */
    private function resolveItemCost(string $companyNit, string $branchId, array $catalogItem): ?float
    {
        if (isset($catalogItem['cost']) && $catalogItem['cost'] !== null) {
            return (float) $catalogItem['cost'];
        }

        $itemId = (string) ($catalogItem['id'] ?? '');
        if ($itemId === '') {
            return null;
        }

        // Costeo por sede (#costeo-multibodega): el costo de receta se computa
        // para la sede de la orden, no cross-sede.
        $recipeCost = (float) $this->recipeCostService->compute($companyNit, $branchId, $itemId)['total_cost'];

        return $recipeCost > 0 ? $recipeCost : null;
    }

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'read');

        $companyNit = $this->activeCompanyNit($request);

        // "Del día" se corta contra America/Bogota (config/orders.timezone).
        // ordered_at se persiste en wall-clock del APP_TIMEZONE (no UTC), así
        // que los límites se convierten a ese tz — convertirlos a ->utc()
        // corría la ventana +5h (el "hoy" iba de 5am a 5am).
        $tz = config('orders.timezone', 'America/Bogota');
        $todayInTz = Carbon::now($tz);
        $startOfDay = $todayInTz->copy()->startOfDay()->setTimezone(config('app.timezone'));
        $endOfDay = $todayInTz->copy()->endOfDay()->setTimezone(config('app.timezone'));

        $orders = Order::forCompany($companyNit)
            ->whereIn('status', config('orders.kanban'))
            ->whereBetween('ordered_at', [$startOfDay, $endOfDay])
            ->with(['delivery.deliverer:id,name'])
            ->orderBy('ordered_at')
            ->get();

        // Lookup batch de chats por telefono — para mostrar el boton "Ir a chat"
        // en el detalle de la orden cuando exista una conversacion del cliente.
        $phones = $orders->pluck('client_phone')->filter()->unique()->values()->all();
        $chatByPhone = empty($phones)
            ? collect()
            : Chat::forCompany($companyNit)
                ->whereIn('client_phone', $phones)
                ->pluck('id', 'client_phone');

        $payload = $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'status' => $order->status,
            'order_type' => $order->order_type,
            'table_number' => $order->table_number,
            'delivery_address' => $order->delivery_address,
            'client_phone' => $order->client_phone,
            'items' => $order->items ?? [],
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'tax_rate' => (float) $order->tax_rate,
            'tip_amount' => (float) $order->tip_amount,
            'total' => (float) $order->total,
            'discount_amount' => (float) $order->discount_amount,
            'coupon_code' => $order->coupon_code,
            'ordered_at' => $order->ordered_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'chat_id' => $order->client_phone ? ($chatByPhone[$order->client_phone] ?? null) : null,
            'delivery' => $order->delivery ? [
                'id' => $order->delivery->id,
                'status' => $order->delivery->status,
                'deliverer' => $order->delivery->deliverer
                    ? ['id' => $order->delivery->deliverer->id, 'name' => $order->delivery->deliverer->name]
                    : null,
            ] : null,
        ]);

        return response()->json(['data' => $payload]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'create');

        $validated = $request->validate([
            'order_type' => ['required', Rule::in(['table', 'delivery', 'pickup'])],
            'client_phone' => ['nullable', new SafePlainText(maxBytes: 32)],
            'table_number' => ['required_if:order_type,table', 'nullable', new SafePlainText(maxBytes: 20)],
            'delivery_address' => ['required_if:order_type,delivery', 'nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
            'coupon_code' => ['nullable', new SafePlainText(maxBytes: 60)],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);

        // Caja debe estar abierta para crear órdenes. La sesión se resuelve por
        // SEDE (#117): cada sede tiene su propia caja abierta.
        $this->cashRegister->requireActiveSession($companyNit, $branchId);

        $menu = RestaurantMenu::forCompany($companyNit)->active()->first();
        if (! $menu) {
            throw ValidationException::withMessages([
                'menu' => 'No hay un menú activo para la empresa.',
            ]);
        }

        $today = (int) now()->format('w');
        $activeDays = $menu->active_days ?? [];
        if (! empty($activeDays) && ! in_array($today, $activeDays, true)) {
            throw ValidationException::withMessages([
                'menu' => 'El menú activo no aplica para hoy.',
            ]);
        }

        $catalog = $this->buildMenuCatalog($menu);
        $company = Company::where('nit', $companyNit)->firstOrFail();
        $items = $this->buildOrderLines($validated['items'], $catalog, $company, $this->activeBranchId($request));

        $aggregate = $this->taxCalculator->aggregate($items);

        // Cupón opcional. Política contable (CO/DIAN-friendly): el descuento reduce
        // la BASE GRAVABLE — re-distribuimos proporcionalmente subtotal y tax para
        // mantener el invariante `total = subtotal + tax_amount` post-descuento.
        $couponContext = $this->applyCouponIfPresent(
            code: $validated['coupon_code'] ?? null,
            companyNit: $companyNit,
            clientPhone: $validated['client_phone'] ?? null,
            aggregate: $aggregate,
        );

        $finalTotal = $couponContext['total'];
        $finalSubtotal = $couponContext['subtotal'];
        $finalTax = $couponContext['tax_amount'];
        $effectiveRate = $finalSubtotal > 0 ? round(($finalTax / $finalSubtotal) * 100, 2) : 0.0;

        // Persistir orden + redención de cupón en una sola transacción para que un
        // fallo en la redención no deje una orden con descuento aplicado pero el
        // contador de uso del cupón sin actualizar.
        $branchId = $this->activeBranchId($request);

        // Si la orden es de mesa, garantizamos que exista una `TableSession`
        // y vinculamos la orden. Esto agrupa todos los pedidos de la mesa
        // bajo una misma sesión sin importar el origen (QR del cliente, o
        // pedido manual creado por el cajero/mesero) — al final "Cobrar
        // mesa" desde /orders/cashier los consolida en una sola transacción.
        // Si ya hay sesión activa en la mesa, se reusa.
        $tableSessionId = null;
        if ($validated['order_type'] === 'table' && ! empty($validated['table_number'])) {
            $tableSessionId = optional(
                app(TableSessionService::class)->ensureSessionForTable(
                    $companyNit,
                    $branchId,
                    (string) $validated['table_number'],
                )
            )->id;
        }

        $order = DB::transaction(function () use ($request, $companyNit, $branchId, $validated, $items, $finalSubtotal, $finalTax, $finalTotal, $effectiveRate, $company, $couponContext, $aggregate, $tableSessionId) {
            $order = Order::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'table_session_id' => $tableSessionId,
                'client_phone' => $validated['client_phone'] ?? null,
                'order_type' => $validated['order_type'],
                'table_number' => $validated['order_type'] === 'table' ? $validated['table_number'] : null,
                'delivery_address' => $validated['order_type'] === 'delivery' ? $validated['delivery_address'] : null,
                'items' => $items,
                'status' => 'pending',
                'subtotal' => $finalSubtotal,
                'tax_amount' => $finalTax,
                'tax_rate' => $effectiveRate,
                'total' => $finalTotal,
                'snapshot_default_tax_rate' => (float) $company->default_tax_rate,
                'tax_regime' => $company->tax_regime,
                'tax_included_in_price' => (bool) $company->tax_included_in_price,
                'cost' => $this->computeOrderCost($items),
                'discount_amount' => $couponContext['discount_amount'],
                'coupon_code' => $couponContext['coupon']?->code,
                'ordered_at' => now(),
            ]);

            if ($couponContext['coupon']) {
                $this->couponService->redeemCoupon(
                    coupon: $couponContext['coupon'],
                    order: $order,
                    clientPhone: $validated['client_phone'] ?? null,
                    discountAmount: $couponContext['discount_amount'],
                    orderTotalBefore: $aggregate['total'],
                    orderTotalAfter: $finalTotal,
                );

                if ($couponContext['auto_applied']) {
                    $this->auditService->log('coupon.auto_applied', $this->actingUser($request), $order, [
                        'coupon_id' => $couponContext['coupon']->id,
                        'coupon_code' => $couponContext['coupon']->code,
                        'discount_amount' => (float) $couponContext['discount_amount'],
                        'order_total_before' => (float) $aggregate['total'],
                        'order_total_after' => (float) $finalTotal,
                    ]);
                }
            }

            $this->materializeOrderItems($order, $items);

            return $order;
        });

        // Vincular contact_id fuera de la txn: permite que CRM y fidelización
        // encuentren la orden por FK directa sin depender del fallback de phone.
        $clientPhone = $validated['client_phone'] ?? null;
        if ($clientPhone !== null && $clientPhone !== '' && $order->contact_id === null) {
            $normalized = CrmService::normalizePhone($clientPhone);
            $alt = str_starts_with($normalized, '57') ? substr($normalized, 2) : '57'.$normalized;
            $contact = Contact::withoutBranchScope()
                ->where('company_nit', $companyNit)
                ->whereIn('phone', array_values(array_unique(array_filter([$clientPhone, $normalized, $alt]))))
                ->orderByDesc('dian_profile_completed_at')
                ->first();
            if ($contact !== null) {
                $order->contact_id = $contact->id;
                $order->save();
            }
        }

        return response()->json([
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'subtotal' => (float) $order->subtotal,
                'tax_amount' => (float) $order->tax_amount,
                'tax_rate' => (float) $order->tax_rate,
                'total' => (float) $order->total,
                'discount_amount' => (float) $order->discount_amount,
                'coupon_code' => $order->coupon_code,
                'items' => $order->items,
                'ordered_at' => $order->ordered_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Valida un cupón contra el agregado bruto y, si es válido, redistribuye el
     * descuento proporcionalmente entre subtotal y tax_amount manteniendo el
     * invariante contable `total = subtotal + tax`.
     *
     * Aproach: el cupón opera sobre el TOTAL bruto. Calculamos `ratio = (gross-discount)/gross`
     * y aplicamos ese ratio al subtotal. El nuevo tax = nuevo_total - nuevo_subtotal
     * (auto-ajusta cualquier centavo de redondeo).
     *
     * @param  array{subtotal: float, tax_amount: float, total: float, effective_rate: float}  $aggregate
     * @return array{coupon: Coupon|null, discount_amount: float, subtotal: float, tax_amount: float, total: float, auto_applied: bool}
     */
    private function applyCouponIfPresent(?string $code, string $companyNit, ?string $clientPhone, array $aggregate): array
    {
        $code = $code ? trim($code) : null;
        $autoApplied = false;

        if (! $code) {
            // Sin código explícito: intenta auto-aplicar un cupón programado (#125 happy hour).
            // Si no hay candidatos válidos para la franja actual, sigue sin cupón.
            $auto = $this->couponService->bestAutoApplyForCart(
                companyNit: $companyNit,
                totalAmount: $aggregate['total'],
                clientPhone: $clientPhone,
            );

            if (! $auto['valid']) {
                return [
                    'coupon' => null,
                    'discount_amount' => 0.0,
                    'subtotal' => $aggregate['subtotal'],
                    'tax_amount' => $aggregate['tax_amount'],
                    'total' => $aggregate['total'],
                    'auto_applied' => false,
                ];
            }

            $result = $auto;
            $autoApplied = true;
        } else {
            $result = $this->couponService->validateCoupon(
                code: $code,
                companyNit: $companyNit,
                totalAmount: $aggregate['total'],
                clientPhone: $clientPhone,
            );

            if (! $result['valid']) {
                throw ValidationException::withMessages([
                    'coupon_code' => $result['error'] ?? 'Cupón inválido.',
                ]);
            }
        }

        $grossTotal = (float) $aggregate['total'];
        $discount = min((float) $result['discount_amount'], $grossTotal);
        $newTotal = round($grossTotal - $discount, 2);

        if ($grossTotal <= 0) {
            $newSubtotal = 0.0;
            $newTax = 0.0;
        } else {
            $ratio = $newTotal / $grossTotal;
            $newSubtotal = round($aggregate['subtotal'] * $ratio, 2);
            // Forzar el invariante: tax = total - subtotal (absorbe redondeo).
            $newTax = round($newTotal - $newSubtotal, 2);
        }

        return [
            'coupon' => $result['coupon'],
            'discount_amount' => round($discount, 2),
            'subtotal' => $newSubtotal,
            'tax_amount' => $newTax,
            'total' => $newTotal,
            'auto_applied' => $autoApplied,
        ];
    }

    /**
     * Construye las líneas de la orden a partir del payload validado, aplicando
     * impuesto por línea (item.tax_rate > company.default_tax_rate).
     *
     * @param  array<int, array<string, mixed>>  $payloadItems
     * @param  Collection<string, array<string, mixed>>  $catalog
     * @return array<int, array<string, mixed>>
     */
    public function buildOrderLines(array $payloadItems, Collection $catalog, Company $company, string $branchId): array
    {
        $lines = [];
        $included = (bool) $company->tax_included_in_price;
        $companyDefaultRate = (float) $company->default_tax_rate;

        foreach ($payloadItems as $line) {
            $catalogItem = $catalog->get($line['id']);
            if (! $catalogItem || ! ($catalogItem['available'] ?? true)) {
                throw ValidationException::withMessages([
                    'items' => 'Uno o más ítems no están disponibles en el menú activo.',
                ]);
            }

            $price = (float) ($catalogItem['price'] ?? 0);
            $quantity = (int) $line['quantity'];
            $itemRate = isset($catalogItem['tax_rate']) ? (float) $catalogItem['tax_rate'] : null;
            $rate = $this->taxCalculator->resolveRate($itemRate, $companyDefaultRate);

            $breakdown = $this->taxCalculator->calculateLine($price, $quantity, $rate, $included);

            // Snapshot del costo unitario al momento de crear la orden. Usa el
            // costo manual del menú o, si no hay, el costo de la receta (BOM).
            // Es null si no hay ninguno; entonces no contribuye al agregado de
            // orders.cost (no se asume 0 para no falsear el food cost).
            // Inmutable post-creación.
            $cost = $this->resolveItemCost($company->nit, $branchId, $catalogItem);

            $lines[] = [
                'id' => (string) $catalogItem['id'],
                'name' => (string) $catalogItem['name'],
                'price' => $price,
                'cost' => $cost,
                'quantity' => $quantity,
                'category' => (string) $catalogItem['category'],
                'notes' => $line['notes'] ?? null,
                // Snapshot tributario por línea (auditable):
                'tax_rate' => $breakdown['tax_rate'],
                'subtotal' => $breakdown['subtotal'],
                'tax_amount' => $breakdown['tax_amount'],
                'total' => $breakdown['total'],
            ];
        }

        return $lines;
    }

    /**
     * Materializa líneas construidas por `buildOrderLines` como filas
     * `order_items` (#293). `order_items` es la fuente de líneas (KDS, pago
     * por item, recálculo de totales); toda creación/append de orden desde
     * caja u offline DEBE llamarlo — sin esto la orden queda invisible para
     * cocina. Nacen `approved` porque el cajero ya validó los items (no
     * `pending_approval`, que es el estado del flujo de comensal QR).
     * Debe correr dentro de la transacción que crea/muta la orden.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function materializeOrderItems(Order $order, array $lines): void
    {
        $now = Carbon::now();
        foreach ($lines as $line) {
            OrderItem::create([
                'order_id' => $order->id,
                'menu_item_id' => (string) $line['id'],
                'guest_id' => null,
                'name' => (string) $line['name'],
                'unit_price' => number_format((float) $line['price'], 2, '.', ''),
                'unit_cost' => isset($line['cost']) && $line['cost'] !== null
                    ? number_format((float) $line['cost'], 2, '.', '')
                    : null,
                'tax_rate' => $line['tax_rate'] ?? null,
                'quantity' => (int) $line['quantity'],
                'category' => $line['category'] ?? null,
                'notes' => $line['notes'] ?? null,
                'status' => 'approved',
                'submitted_at' => $now,
                'approved_at' => $now,
            ]);
        }
    }

    /**
     * Suma el costo total de la orden a partir de items[].cost. Líneas con cost=null
     * se omiten (food cost parcial) para no contaminar el agregado con 0 falsos.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function computeOrderCost(array $items): ?float
    {
        $total = 0.0;
        $hasCost = false;
        foreach ($items as $line) {
            $cost = $line['cost'] ?? null;
            if ($cost === null) {
                continue;
            }
            $hasCost = true;
            $total += (float) $cost * (int) ($line['quantity'] ?? 0);
        }

        return $hasCost ? round($total, 2) : null;
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'read');

        $companyNit = $this->activeCompanyNit($request);

        $order = Order::forCompany($companyNit)
            ->with(['delivery.deliverer:id,name', 'receipts' => fn ($q) => $q->orderByDesc('paid_at')->orderByDesc('created_at')])
            ->findOrFail($id);

        // Notas grupales (scope=group) y alertas de cocina (scope=kitchen_alert)
        // viven en `order_notes`. Autor puede ser User (mesero) o
        // TableSessionGuest (comensal del QR). Devolvemos un label legible para
        // que la UI no tenga que resolver morphTo del lado cliente.
        $orderNotes = OrderNote::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get(['id', 'scope', 'body', 'author_type', 'author_id', 'created_at']);

        $guestIds = $orderNotes
            ->where('author_type', TableSessionGuest::class)
            ->pluck('author_id')
            ->filter()
            ->unique()
            ->all();
        $guestsById = empty($guestIds)
            ? collect()
            : TableSessionGuest::withoutGlobalScopes()->whereIn('id', $guestIds)->get(['id', 'display_name'])->keyBy('id');

        $userIds = $orderNotes
            ->where('author_type', User::class)
            ->pluck('author_id')
            ->filter()
            ->unique()
            ->all();
        $usersById = empty($userIds)
            ? collect()
            : User::query()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        $notesPayload = $orderNotes->map(function (OrderNote $n) use ($guestsById, $usersById): array {
            $authorLabel = null;
            $authorRole = null;
            if ($n->author_type === TableSessionGuest::class && $n->author_id !== null) {
                $authorLabel = optional($guestsById->get($n->author_id))->display_name ?? 'Comensal';
                $authorRole = 'guest';
            } elseif ($n->author_type === User::class && $n->author_id !== null) {
                $authorLabel = optional($usersById->get($n->author_id))->name ?? 'Mesero';
                $authorRole = 'waiter';
            }

            return [
                'id' => $n->id,
                'scope' => $n->scope,
                'body' => $n->body,
                'author_label' => $authorLabel,
                'author_role' => $authorRole,
                'created_at' => optional($n->created_at)?->toIso8601String(),
            ];
        })->values()->all();

        // Items con notas individuales. Para órdenes de mesa con QR los items
        // viven en `order_items` rows; para órdenes legacy (delivery/pickup)
        // viven en `orders.items` JSON. Unificamos en `line_items` para que
        // el frontend tenga una sola estructura, conservando `items` legacy
        // por compatibilidad con consumidores existentes.
        $orderItems = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get(['id', 'menu_item_id', 'name', 'quantity', 'unit_price', 'notes', 'status', 'cancellation_reason', 'guest_id', 'refunded_at']);

        $lineItems = $orderItems->isNotEmpty()
            ? $orderItems->map(function (OrderItem $i) use ($guestsById): array {
                return [
                    'id' => $i->id,
                    'menu_item_id' => $i->menu_item_id,
                    'name' => $i->name,
                    'quantity' => (int) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'notes' => $i->notes,
                    'status' => $i->status,
                    'cancellation_reason' => $i->cancellation_reason,
                    'guest_label' => $i->guest_id ? optional($guestsById->get($i->guest_id))->display_name : null,
                    'refunded_at' => optional($i->refunded_at)?->toIso8601String(),
                ];
            })->values()->all()
            : collect($order->items ?? [])->map(fn ($i) => [
                'id' => $i['id'] ?? null,
                'menu_item_id' => $i['menu_item_id'] ?? ($i['id'] ?? null),
                'name' => $i['name'] ?? '',
                'quantity' => (int) ($i['quantity'] ?? 1),
                'unit_price' => (float) ($i['price'] ?? $i['unit_price'] ?? 0),
                'notes' => $i['notes'] ?? null,
                'status' => $i['status'] ?? null,
                'cancellation_reason' => null,
                'guest_label' => null,
            ])->values()->all();

        $chatId = $order->client_phone
            ? Chat::forCompany($companyNit)->where('client_phone', $order->client_phone)->value('id')
            : null;

        // Órdenes hermanas de la misma sesión de mesa. Cuando un cliente
        // sienta en una mesa y el mesero/cajero aprueba varias tandas, cada
        // una se materializa como Order separada vinculada a la misma
        // `table_session_id`. Acá listamos las hermanas para que la UI de
        // detalle muestre "esta es 1 de 3" + links a las demás.
        $relatedOrders = [];
        if ($order->table_session_id !== null) {
            $relatedOrders = Order::withoutGlobalScopes()
                ->where('table_session_id', $order->table_session_id)
                ->where('id', '!=', $order->id)
                ->where('status', '!=', 'pending_approval')
                ->orderBy('ordered_at')
                ->orderBy('id')
                ->get(['id', 'status', 'total', 'ordered_at', 'table_number'])
                ->map(fn (Order $o) => [
                    // orders.id es uuid → string, no int.
                    'id' => (string) $o->id,
                    'status' => $o->status,
                    'total' => (float) $o->total,
                    'ordered_at' => optional($o->ordered_at)?->toIso8601String(),
                    'table_number' => $o->table_number,
                ])
                ->values()
                ->all();
        }

        // Último pago (excluye refunds) y último refund. Lee desde columnas estructuradas.
        $lastPayment = $order->receipts->first(fn (PaymentReceipt $r) => $r->payment_method !== null && $r->payment_method !== 'refund');
        $lastRefund = $order->receipts->first(fn (PaymentReceipt $r) => $r->payment_method === 'refund');

        // Refunds totales sobre la orden (soporta parciales múltiples).
        $totalRefundedAmount = (float) $order->receipts
            ->where('payment_method', 'refund')
            ->sum(fn (PaymentReceipt $r) => abs((float) $r->amount));
        $remainingRefundable = $lastPayment
            ? round((float) $order->total - $totalRefundedAmount, 2)
            : 0.0;

        return response()->json([
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'order_type' => $order->order_type,
                'table_number' => $order->table_number,
                'delivery_address' => $order->delivery_address,
                'client_phone' => $order->client_phone,
                'items' => $order->items ?? [],
                'line_items' => $lineItems,
                'notes' => $notesPayload,
                'table_session_id' => $order->table_session_id,
                'related_orders' => $relatedOrders,
                'subtotal' => (float) $order->subtotal,
                'tax_amount' => (float) $order->tax_amount,
                'tax_rate' => (float) $order->tax_rate,
                'tax_regime' => $order->tax_regime,
                'tax_included_in_price' => (bool) $order->tax_included_in_price,
                'tip_amount' => (float) $order->tip_amount,
                'total' => (float) $order->total,
                'discount_amount' => (float) $order->discount_amount,
                'coupon_code' => $order->coupon_code,
                'ordered_at' => $order->ordered_at?->toIso8601String(),
                'chat_id' => $chatId,
                'delivery' => $order->delivery ? [
                    'id' => $order->delivery->id,
                    'status' => $order->delivery->status,
                    'deliverer' => $order->delivery->deliverer
                        ? ['id' => $order->delivery->deliverer->id, 'name' => $order->delivery->deliverer->name]
                        : null,
                ] : null,
                'payment' => $lastPayment ? [
                    'method' => $lastPayment->payment_method,
                    'amount' => (float) $lastPayment->amount,
                    'reference' => $lastPayment->reference,
                    'amount_received' => $lastPayment->payment_data['amount_received'] ?? null,
                    'change_returned' => $lastPayment->payment_data['change_returned'] ?? null,
                    'paid_at' => $lastPayment->paid_at?->toIso8601String(),
                ] : null,
                'refund' => $lastRefund ? [
                    'original_method' => $lastRefund->payment_data['original_method'] ?? null,
                    'total_refunded' => abs((float) $lastRefund->amount),
                    'reference' => $lastRefund->reference,
                    'refunded_at' => $lastRefund->paid_at?->toIso8601String(),
                    // Acumulados (multiples refunds parciales).
                    'total_refunded_all' => $totalRefundedAmount,
                    'remaining_refundable' => $remainingRefundable,
                    'is_partial' => $totalRefundedAmount > 0 && $remainingRefundable > 0,
                ] : null,
            ],
        ]);
    }

    /**
     * Lista las mesas con una orden actualmente abierta (status operativo, no completada/cancelada).
     * Una mesa se considera "ocupada" si tiene al menos una orden con order_type='table'
     * en estados pending|in_kitchen|ready. Devuelve un map indexado por table_number.
     */
    public function tables(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'read');

        $companyNit = $this->activeCompanyNit($request);

        $orders = Order::forCompany($companyNit)
            ->where('order_type', 'table')
            ->whereIn('status', ['pending', 'in_kitchen', 'ready'])
            ->whereNotNull('table_number')
            ->orderBy('ordered_at')
            // Cancelados fuera: no deben aparecer en la vista de mesas ni sumar
            // al item_count (paridad con la proyección orders.items JSON).
            ->with(['orderItems' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('id')])
            ->get();

        $payload = $orders->map(function (Order $order): array {
            // QR orders store items in order_items rows; legacy orders use the JSON column.
            $items = $order->orderItems->isNotEmpty()
                ? $order->orderItems->map(fn (OrderItem $i): array => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'quantity' => (int) $i->quantity,
                    'price' => (float) $i->unit_price,
                    'category' => '',
                    'notes' => $i->notes,
                ])->values()->all()
                : ($order->items ?? []);

            return [
                'id' => $order->id,
                'status' => $order->status,
                'table_number' => $order->table_number,
                'items' => $items,
                'item_count' => collect($items)->sum('quantity'),
                'subtotal' => (float) $order->subtotal,
                'tax_amount' => (float) $order->tax_amount,
                'tax_rate' => (float) $order->tax_rate,
                'tax_included_in_price' => (bool) $order->tax_included_in_price,
                'total' => (float) $order->total,
                'tip_amount' => (float) $order->tip_amount,
                'client_phone' => $order->client_phone,
                'ordered_at' => $order->ordered_at?->toIso8601String(),
            ];
        })->values();

        return response()->json(['data' => $payload]);
    }

    /**
     * Agrega ítems a una orden de mesa abierta. La cuenta queda viva durante el servicio
     * y se cierra al final (avanzando status a completed/successful via updateStatus).
     *
     * Reglas:
     *  - La orden debe ser order_type='table' y estar en un estado abierto (no completed/successful/cancelled/abandoned).
     *  - Los precios se leen del menú activo en DB; nunca se confía en lo enviado por el cliente.
     *  - Recalcula `total` sumando los nuevos ítems al total existente.
     */
    public function appendItems(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'update');

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);

        // Caja debe estar abierta para agregar ítems a una mesa (afecta total a cobrar).
        $this->cashRegister->requireActiveSession($companyNit, $branchId);

        $menu = RestaurantMenu::forCompany($companyNit)->active()->first();
        if (! $menu) {
            throw ValidationException::withMessages([
                'menu' => 'No hay un menú activo para la empresa.',
            ]);
        }

        $catalog = $this->buildMenuCatalog($menu);
        $company = Company::where('nit', $companyNit)->firstOrFail();
        $actor = $this->actingUser($request);

        // Transacción: Order::lockForUpdate evita que dos requests concurrentes
        // dupliquen ítems o dejen items[]/total inconsistentes. Usamos el snapshot
        // tributario de la orden (no el actual de la empresa) para mantener
        // coherencia en órdenes ya iniciadas.
        $result = DB::transaction(function () use ($id, $companyNit, $validated, $catalog, $company, $actor) {
            /** @var Order $order */
            $order = Order::forCompany($companyNit)->lockForUpdate()->findOrFail($id);

            if ($order->order_type !== 'table') {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden agregar ítems a órdenes de mesa.',
                ]);
            }

            $closedStatuses = array_merge(config('orders.terminal_success'), config('orders.terminal_failure'));
            if (in_array($order->status, $closedStatuses, true)) {
                throw ValidationException::withMessages([
                    'order' => 'La orden ya está cerrada y no admite nuevos ítems.',
                ]);
            }

            // Para append usamos el snapshot tributario de la orden (immutable).
            // Si el régimen de la empresa cambió mientras la mesa estaba abierta,
            // los nuevos ítems siguen el régimen original de la cuenta. Usamos
            // `snapshot_default_tax_rate` (no `tax_rate` effective) para evitar
            // contaminar nuevos ítems con el promedio ponderado de los existentes.
            $included = (bool) $order->tax_included_in_price;
            $companyDefaultRate = (float) ($order->snapshot_default_tax_rate ?? $company->default_tax_rate);

            $newLines = [];
            $addedTotal = 0.0;

            foreach ($validated['items'] as $line) {
                $catalogItem = $catalog->get($line['id']);
                if (! $catalogItem || ! ($catalogItem['available'] ?? true)) {
                    throw ValidationException::withMessages([
                        'items' => 'Uno o más ítems no están disponibles en el menú activo.',
                    ]);
                }

                $price = (float) ($catalogItem['price'] ?? 0);
                $quantity = (int) $line['quantity'];
                $itemRate = isset($catalogItem['tax_rate']) ? (float) $catalogItem['tax_rate'] : null;
                $rate = $this->taxCalculator->resolveRate($itemRate, $companyDefaultRate);

                $breakdown = $this->taxCalculator->calculateLine($price, $quantity, $rate, $included);

                $cost = $this->resolveItemCost($order->company_nit, (string) $order->branch_id, $catalogItem);

                $newLines[] = [
                    'id' => (string) $catalogItem['id'],
                    'name' => (string) $catalogItem['name'],
                    'price' => $price,
                    'cost' => $cost,
                    'quantity' => $quantity,
                    'category' => (string) $catalogItem['category'],
                    'notes' => $line['notes'] ?? null,
                    'tax_rate' => $breakdown['tax_rate'],
                    'subtotal' => $breakdown['subtotal'],
                    'tax_amount' => $breakdown['tax_amount'],
                    'total' => $breakdown['total'],
                ];

                $addedTotal += $breakdown['total'];
            }

            // ¿La orden ya está respaldada por filas `order_items`? Todo lo
            // creado post-#293 lo está (caja, QR, sync offline); solo órdenes
            // legacy abiertas antes del dual-write carecen de filas.
            $hadRows = OrderItem::query()->where('order_id', $order->id)->exists();

            // Materializar las líneas nuevas como filas `order_items` — sin
            // esto los items agregados quedaban invisibles para el KDS y el
            // pago por item (#293).
            $this->materializeOrderItems($order, $newLines);

            if ($hadRows) {
                // Fuente única: filas. Recalcula subtotal/tax/total (con
                // prorrateo de cupón) y proyecta `orders.items` JSON + cost.
                // Cubre también órdenes QR a las que caja agrega items —
                // antes el total se pisaba con solo las líneas nuevas porque
                // el JSON de esas órdenes estaba vacío.
                $this->orderTotals->recalculateAndSave($order);
            } else {
                $order->items = array_merge($order->items ?? [], $newLines);
                // Recalcular agregados desde items[] para garantizar invariantes.
                // Si la orden nació con cupón, re-aplicar el descuento ABSOLUTO
                // snapshoteado en la redención (discount_amount) sobre el nuevo
                // bruto — sin esto, agregar ítems pisaba el total con el bruto y
                // el descuento "desaparecía" dejando discount_amount inconsistente.
                $aggregate = $this->taxCalculator->aggregate($order->items);
                $discount = min((float) $order->discount_amount, (float) $aggregate['total']);
                if ($discount > 0 && $aggregate['total'] > 0) {
                    $netTotal = round($aggregate['total'] - $discount, 2);
                    $ratio = $netTotal / $aggregate['total'];
                    $order->subtotal = round($aggregate['subtotal'] * $ratio, 2);
                    // Invariante: tax = total - subtotal (absorbe redondeo).
                    $order->tax_amount = round($netTotal - $order->subtotal, 2);
                    $order->total = $netTotal;
                } else {
                    $order->subtotal = $aggregate['subtotal'];
                    $order->tax_amount = $aggregate['tax_amount'];
                    $order->total = $aggregate['total'];
                }
                $order->cost = $this->computeOrderCost($order->items);
                $order->save();
            }

            // Si la orden ya pasó por cocina (inventario ya descontado),
            // descontar también el delta de las nuevas líneas — el resto no
            // debe re-descontarse. Si aún no pasó por cocina, el descuento
            // se hará en bloque al pasar a in_kitchen.
            if ($order->inventory_consumed_at !== null) {
                $this->inventoryService->consumeForOrder($order, $newLines, $actor, 'order.append_items');
            }

            return [$order, $addedTotal];
        });

        [$order, $addedTotal] = $result;

        $this->auditService->log('order.items_appended', $this->actingUser($request), $order, [
            'order_id' => $order->id,
            'added_total' => $addedTotal,
            'new_total' => (float) $order->total,
            'lines_added' => count($validated['items']),
        ]);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'items' => $order->items,
                'total' => (float) $order->total,
                'added_total' => $addedTotal,
            ],
        ]);
    }

    /**
     * Construye un catálogo `[id => item+category]` desde el menú activo.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function buildMenuCatalog(RestaurantMenu $menu): Collection
    {
        return collect($menu->structure['categories'] ?? [])
            ->flatMap(function (array $category): array {
                $items = $category['items'] ?? [];

                return collect($items)
                    ->map(fn (array $item): array => [
                        ...$item,
                        'category' => $category['name'] ?? '',
                    ])
                    ->all();
            })
            ->keyBy('id');
    }

    /**
     * Calcula total = SUM(price * quantity) sobre el array de ítems persistido en
     * Order::items. Único punto de cálculo para evitar divergencia.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function computeItemsTotal(array $items): float
    {
        $total = 0.0;
        foreach ($items as $line) {
            $total += (float) ($line['price'] ?? 0) * (int) ($line['quantity'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Cierra una orden cobrando con un método de pago (cash|card|transfer) y persiste
     * un PaymentReceipt con los detalles. Para efectivo, calcula y almacena la devuelta.
     * Para transferencia, asume que el cobro se hizo vía QR de la empresa.
     *
     * Reglas:
     *  - La orden debe pertenecer a la empresa activa y no estar en estado terminal.
     *  - Para 'cash', amount_received >= total.
     *  - Tras el cobro, status pasa a 'completed'.
     */
    public function closeWithPayment(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'update');

        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(['cash', 'card', 'transfer', 'nequi', 'daviplata'])],
            'amount_received' => ['required_if:payment_method,cash', 'nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', new SafePlainText(maxBytes: 120)],
            // Propina voluntaria (CO). NO suma a total ni a base gravable, NO genera
            // impuesto. Si la pagan en efectivo el cliente entrega total + tip y la
            // devuelta se calcula contra ese expectedTotal.
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            // Multi-caja (#117): contra qué caja se cobra. Opcional para
            // retrocompat (sede mono-caja → única caja abierta).
            'cash_session_id' => ['nullable', 'uuid'],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);

        // Caja debe estar abierta. La sesión (la caja operada) se asocia al
        // receipt para que el cierre de caja calcule el efectivo esperado.
        $session = $this->cashRegister->resolveSessionForCharge($companyNit, $branchId, $validated['cash_session_id'] ?? null);

        // Atomicidad: bloquear la orden, validar estado, crear receipt y actualizar
        // status en una sola transacción. lockForUpdate evita doble cierre concurrente.
        [$order, $paidAt, $paymentData] = DB::transaction(function () use ($id, $companyNit, $validated, $session) {
            /** @var Order $order */
            $order = Order::forCompany($companyNit)->lockForUpdate()->findOrFail($id);

            $closedStatuses = array_merge(config('orders.terminal_success'), config('orders.terminal_failure'));
            if (in_array($order->status, $closedStatuses, true)) {
                throw ValidationException::withMessages([
                    'order' => 'La orden ya está cerrada.',
                ]);
            }

            $total = (float) $order->total;
            $tip = round((float) ($validated['tip_amount'] ?? 0), 2);
            // Lo que el cliente debe entregar = total + propina. La propina NO se
            // suma al revenue ni al payment_receipts.amount.
            $expectedTotal = round($total + $tip, 2);
            $paidAt = now();

            $paymentData = [
                'method' => $validated['payment_method'],
                'total' => $total,
                'tip_amount' => $tip,
                'expected_total' => $expectedTotal,
                'paid_at' => $paidAt->toIso8601String(),
                'reference' => $validated['reference'] ?? null,
            ];

            if ($validated['payment_method'] === 'cash') {
                $amountReceived = (float) $validated['amount_received'];
                if ($amountReceived + 0.0001 < $expectedTotal) {
                    throw ValidationException::withMessages([
                        'amount_received' => 'El monto recibido es menor al total a cobrar (incluyendo propina).',
                    ]);
                }
                $paymentData['amount_received'] = $amountReceived;
                $paymentData['change_returned'] = round($amountReceived - $expectedTotal, 2);
            }

            $receipt = PaymentReceipt::create([
                'order_id' => $order->id,
                'company_nit' => $companyNit,
                'branch_id' => $order->branch_id,
                'file_path' => null,
                // Columnas estructuradas (fuente de verdad contable). amount es el
                // INGRESO de la empresa: NO incluye propina (la propina es del staff).
                'payment_method' => match ($validated['payment_method']) {
                    'nequi', 'daviplata' => 'transfer',
                    default => $validated['payment_method'],
                },
                'amount' => $total,
                'reference' => $validated['reference'] ?? null,
                'paid_at' => $paidAt,
                'cash_session_id' => $session->id,
                'payment_data' => $paymentData,
            ]);

            // Persistir propina en la orden para reportes (separada de revenue).
            $order->tip_amount = $tip;
            $order->status = 'completed';
            $order->save();

            // La orden quedó cerrada: cualquier item aún abierto en cocina pasa
            // a `served` para que salga del KDS y el estado quede consistente.
            $this->markOpenKitchenItemsServed($order);

            // El receipt cubre el total de la orden: marcar TODOS los items no
            // cancelados como pagados. Sin esto, el cobro de mesa
            // (TableCashierService) los seguía viendo con paid_at NULL y ofrecía
            // re-cobrarlos (doble cobro).
            $this->markOrderItemsPaid($order, $receipt->id, $paidAt);

            return [$order, $paidAt, $paymentData];
        });

        // Vincular contact_id si aún está sin asignar (órdenes de Mesa no lo traen
        // desde la creación). Idempotente — no toca si ya está asignado.
        if ($order->contact_id === null && $order->client_phone !== null && $order->client_phone !== '') {
            $normalized = CrmService::normalizePhone((string) $order->client_phone);
            $alt = str_starts_with($normalized, '57') ? substr($normalized, 2) : '57'.$normalized;
            $contact = Contact::withoutBranchScope()
                ->where('company_nit', $order->company_nit)
                ->whereIn('phone', array_values(array_unique(array_filter([$order->client_phone, $normalized, $alt]))))
                ->orderByDesc('dian_profile_completed_at')
                ->first();
            if ($contact !== null) {
                $order->contact_id = $contact->id;
                $order->save();
            }
        }

        $actor = $this->actingUser($request);

        $this->auditService->log('order.closed_with_payment', $actor, $order, [
            'order_id' => $order->id,
            'method' => $paymentData['method'],
            'amount' => $paymentData['total'],
            'tip_amount' => $paymentData['tip_amount'] ?? 0,
            'reference' => $paymentData['reference'] ?? null,
            'change_returned' => $paymentData['change_returned'] ?? null,
        ]);

        // SMS al cliente FUERA de la txn de cobro: su fallo nunca revierte el
        // pago ya commiteado (#275 Fase 4 / CLAUDE.md §13).
        $this->dispatchOrderStatusSms($order, 'completed', $actor);

        // Fidelización (#122): award fuera de la transacción de cobro para que
        // un fallo del programa de puntos NUNCA reverse un cobro válido. El
        // service es idempotente por order_id vía UNIQUE PARCIAL.
        $loyaltyMovement = null;
        try {
            $loyaltyMovement = $this->loyaltyService->award($order, $this->actingUser($request));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'total' => $paymentData['total'],
                'tip_amount' => $paymentData['tip_amount'] ?? 0,
                'expected_total' => $paymentData['expected_total'],
                'payment' => $paymentData,
                'loyalty' => $loyaltyMovement ? [
                    'points_awarded' => $loyaltyMovement->points,
                    'movement_id' => $loyaltyMovement->id,
                ] : null,
            ],
        ]);
    }

    /**
     * Cancela una orden no pagada (sin PaymentReceipt) y la marca como `cancelled`.
     * Las órdenes pagadas o completadas deben usar el flujo de devolución (`refund`).
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'update');

        $validated = $request->validate([
            'reason' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $blocked = array_merge(config('orders.terminal_success'), config('orders.terminal_failure'));

        $order = DB::transaction(function () use ($id, $companyNit, $blocked) {
            /** @var Order $order */
            $order = Order::forCompany($companyNit)->lockForUpdate()->findOrFail($id);

            if (in_array($order->status, $blocked, true)) {
                throw ValidationException::withMessages([
                    'order' => 'Esta orden no puede cancelarse en su estado actual.',
                ]);
            }

            // Si tiene cobros previos (PaymentReceipt con payment_method != 'refund'),
            // el camino correcto es Devolver para que el ingreso quede compensado por
            // un asiento de signo opuesto en lugar de "desaparecer" al cancelarse.
            $hasPayment = $order->receipts()
                ->where('payment_method', '!=', 'refund')
                ->whereNotNull('payment_method')
                ->exists();

            if ($hasPayment) {
                throw ValidationException::withMessages([
                    'order' => 'La orden ya tiene un pago registrado. Usa "Devolver" para procesar la devolución.',
                ]);
            }

            $order->status = 'cancelled';
            $order->save();

            // Cerrar ítems que quedaron abiertos (operational + pending_approval)
            // para que salgan del KDS y el estado quede consistente.
            OrderItem::query()
                ->where('order_id', $order->id)
                ->whereNotIn('status', ['served', 'cancelled'])
                ->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => 'system',
                    'cancelled_at' => now(),
                ]);

            return $order;
        });

        $this->auditService->log('order.cancelled', $this->actingUser($request), $order, [
            'order_id' => $order->id,
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'data' => ['id' => $order->id, 'status' => $order->status],
        ]);
    }

    /**
     * Procesa una devolución de una orden pagada. Para tarjeta y transferencia exige
     * `reference` (número de comprobante de la devolución). Crea un PaymentReceipt de tipo
     * refund con `payment_data.method='refund'` para que la contabilidad pueda diferenciarlo.
     * La orden queda en estado `refunded`.
     */
    public function refund(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'update');

        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $cashSessionId = $request->input('cash_session_id');

        // Las devoluciones también afectan la caja física (efectivo o no), por
        // lo que requieren caja abierta (de esta sede) para asociarlas a una
        // sesión auditable. Multi-caja (#117): la caja operada se recibe
        // explícita o se infiere si la sede tiene una sola abierta.
        $session = $this->cashRegister->resolveSessionForCharge(
            $companyNit,
            $branchId,
            is_string($cashSessionId) ? $cashSessionId : null,
        );

        // Pre-flight: identificamos el método original ANTES de la transacción
        // para validar `reference` (regla condicional sobre `card|transfer`).
        $preOrder = Order::forCompany($companyNit)
            ->with(['receipts' => fn ($q) => $q->orderByDesc('paid_at')->orderByDesc('created_at')])
            ->findOrFail($id);

        if (in_array($preOrder->status, ['cancelled', 'abandoned'], true)) {
            throw ValidationException::withMessages([
                'order' => 'La orden ya fue cancelada y no admite devolución.',
            ]);
        }

        /** @var PaymentReceipt|null $lastPayment */
        $lastPayment = $preOrder->receipts->first(fn (PaymentReceipt $r) => $r->payment_method !== null && $r->payment_method !== 'refund');
        if (! $lastPayment) {
            throw ValidationException::withMessages([
                'order' => 'La orden no tiene un pago registrado. Usa "Cancelar" en su lugar.',
            ]);
        }

        $originalMethod = $lastPayment->payment_method;
        $requiresReference = in_array($originalMethod, ['card', 'transfer'], true);

        // Refunds previos sobre esta orden (parciales). amount < 0 → sumamos abs.
        $alreadyRefunded = (float) $preOrder->receipts
            ->where('payment_method', 'refund')
            ->sum(fn (PaymentReceipt $r) => abs((float) $r->amount));

        $orderTotal = (float) $preOrder->total;
        $remainingRefundable = round($orderTotal - $alreadyRefunded, 2);

        if ($remainingRefundable <= 0) {
            throw ValidationException::withMessages([
                'order' => 'La orden ya fue devuelta en su totalidad.',
            ]);
        }

        $validated = $request->validate([
            'reference' => [$requiresReference ? 'required' : 'nullable', new SafePlainText(maxBytes: 120)],
            'reason' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            // Monto a devolver. Si se omite, se devuelve el remanente completo
            // (compatibilidad con el flujo actual de "Devolución total").
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:'.$remainingRefundable],
        ], [
            'reference.required' => 'Para devoluciones de tarjeta o transferencia es obligatorio el número de comprobante de la devolución.',
            'amount.max' => 'El monto a devolver no puede superar el remanente disponible (máx '.number_format($remainingRefundable, 2).').',
        ]);

        $requestedAmount = isset($validated['amount']) ? round((float) $validated['amount'], 2) : $remainingRefundable;

        // Atomicidad: revalidamos dentro de la transacción contra una versión bloqueada.
        [$order, $refundAmount, $newRemaining] = DB::transaction(function () use ($id, $companyNit, $validated, $originalMethod, $requestedAmount, $session) {
            /** @var Order $order */
            $order = Order::forCompany($companyNit)->with('receipts')->lockForUpdate()->findOrFail($id);

            if (in_array($order->status, ['cancelled', 'abandoned'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'La orden ya fue cancelada y no admite devolución.',
                ]);
            }

            // Recalcular dentro del lock para evitar race con otro refund concurrente.
            $alreadyRefundedTx = (float) $order->receipts
                ->where('payment_method', 'refund')
                ->sum(fn (PaymentReceipt $r) => abs((float) $r->amount));
            $orderTotalTx = (float) $order->total;
            $remainingTx = round($orderTotalTx - $alreadyRefundedTx, 2);

            if ($remainingTx <= 0) {
                throw ValidationException::withMessages([
                    'order' => 'La orden ya fue devuelta en su totalidad.',
                ]);
            }

            // Si entre el pre-flight y el lock entró otro refund concurrente que
            // dejó el remanente menor al solicitado, RECHAZAMOS en lugar de
            // silenciosamente refundar menos. La operación debe ser atómica:
            // o se devuelve exactamente lo solicitado, o no se devuelve nada.
            if ($requestedAmount > $remainingTx + 0.001) {
                throw ValidationException::withMessages([
                    'amount' => 'El monto solicitado excede el remanente disponible. Otro reembolso pudo haberse procesado simultáneamente. Remanente actual: '.number_format($remainingTx, 2).'.',
                ]);
            }

            $amountToRefund = round($requestedAmount, 2);
            $newRemaining = round($remainingTx - $amountToRefund, 2);
            $refundedAt = now();

            PaymentReceipt::create([
                'order_id' => $order->id,
                'company_nit' => $companyNit,
                'branch_id' => $order->branch_id,
                'file_path' => null,
                // amount NEGATIVO; SUM(amount) por método sigue dando el neto.
                'payment_method' => 'refund',
                'amount' => -$amountToRefund,
                'reference' => $validated['reference'] ?? null,
                'paid_at' => $refundedAt,
                'cash_session_id' => $session->id,
                'payment_data' => [
                    'method' => 'refund',
                    'original_method' => $originalMethod,
                    'total_refunded' => $amountToRefund,
                    'is_partial' => $newRemaining > 0,
                    'remaining_refundable' => $newRemaining,
                    'reference' => $validated['reference'] ?? null,
                    'reason' => $validated['reason'] ?? null,
                    'refunded_at' => $refundedAt->toIso8601String(),
                ],
            ]);

            // Status: refunded sólo cuando se devolvió todo. En refunds parciales
            // la orden conserva su status actual (típicamente completed) para que
            // siga contando como ingreso en gross — el SUM(amount) signed ajusta
            // automáticamente el net.
            if ($newRemaining <= 0) {
                $order->status = 'refunded';
                $order->save();
            }

            return [$order, $amountToRefund, $newRemaining];
        });

        $this->auditService->log('order.refunded', $this->actingUser($request), $order, [
            'order_id' => $order->id,
            'original_method' => $originalMethod,
            'total_refunded' => $refundAmount,
            'is_partial' => $newRemaining > 0,
            'remaining_refundable' => $newRemaining,
            'reference' => $validated['reference'] ?? null,
            'reason' => $validated['reason'] ?? null,
        ]);

        // Fidelización (#122): solo reversamos puntos cuando el refund es total.
        // En refunds parciales mantenemos el award para no romper el incentivo
        // del cliente; la columna lifetime_earned puede recalibrarse a futuro
        // si se implementa reversa proporcional.
        if ($newRemaining <= 0) {
            try {
                $this->loyaltyService->refundReverse($order, $this->actingUser($request));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'refund' => [
                    'original_method' => $originalMethod,
                    'total_refunded' => $refundAmount,
                    'is_partial' => $newRemaining > 0,
                    'remaining_refundable' => $newRemaining,
                    'reference' => $validated['reference'] ?? null,
                ],
            ],
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'update');

        $validated = $request->validate([
            'status' => ['required', Rule::in(config('orders.kanban'))],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $actor = $this->actingUser($request);

        [$order, $previousStatus, $consumed, $inventoryWarnings] = DB::transaction(function () use ($id, $companyNit, $validated, $actor) {
            /** @var Order $order */
            $order = Order::forCompany($companyNit)->lockForUpdate()->findOrFail($id);
            $previous = $order->status;
            $target = $validated['status'];

            $this->assertForwardOnlyTransition($previous, $target);

            if ($previous === $target) {
                // No-op silencioso: idempotencia para drag-and-drop torpe.
                return [$order, $previous, false, []];
            }

            // `completed` = entrega operativa consumada (plato en mesa, domicilio
            // entregado). El cobro es un evento separado que llega vía
            // closeWithPayment() y crea el PaymentReceipt; no se exige aquí para
            // no bloquear el flujo de cocina/delivery cuando el pago es posterior.

            $order->status = $target;
            $order->save();

            // Descuento de inventario al pasar a cocina (idempotente vía
            // inventory_consumed_at). Si no hay receta para algún ítem, se
            // ignora silenciosamente con audit; nunca bloquea el flujo de cocina.
            $consumedNow = false;
            $inventoryWarnings = [];
            if ($target === 'in_kitchen' && $order->inventory_consumed_at === null) {
                $inventoryWarnings = $this->inventoryService->consumeForOrder($order, $order->items ?? [], $actor, 'order.in_kitchen');
                $order->inventory_consumed_at = now();
                $order->save();
                $consumedNow = true;
            }

            // Mantener /kds y /orders/board sincronizados: al mover la orden en
            // el tablero empujamos sus order_items al estado de cocina
            // correspondiente (forward-only). Sin esto el KDS seguía mostrando
            // los items en el estado viejo (p. ej. orden en "ready" con tickets
            // todavía en "approved", o completada con tickets colgados).
            $this->syncItemsToOrderStatus($order, $target);

            return [$order, $previous, $consumedNow, $inventoryWarnings];
        });

        if ($previousStatus !== $order->status) {
            $this->auditService->log('order.status_changed', $actor, $order, [
                'order_id' => $order->id,
                'from' => $previousStatus,
                'to' => $order->status,
                'inventory_consumed' => $consumed,
            ]);

            // SMS al cliente FUERA de la txn: su fallo nunca revierte el cambio
            // de estado ya commiteado (#275 Fase 4). user_id = quien arrastró,
            // para avisarle si el envío async falla.
            $this->dispatchOrderStatusSms($order, $order->status, $actor);
        }

        return response()->json([
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'inventory_consumed_at' => $order->inventory_consumed_at
                    ? Carbon::parse($order->inventory_consumed_at)->toIso8601String()
                    : null,
                'inventory_warnings' => $inventoryWarnings,
            ],
        ]);
    }

    /**
     * Despacha el SMS de cambio de estado FUERA de la transacción del cambio de
     * estado, de forma que ningún fallo del SMS (registro, encolado, etc.) pueda
     * abortar la txn y revertir el cambio en el tablero (CLAUDE.md §12/§13: el
     * efecto secundario nunca compromete la mutación de negocio ya commiteada).
     *
     * Delega en `OrderStatusSmsDispatcher` (compartido con KdsTicketService,
     * TableCashierService, SyncController y OrderSyncController — todos los
     * caminos que mutan `orders.status` a un estado notificable).
     */
    private function dispatchOrderStatusSms(Order $order, string $toStatus, ?User $user): void
    {
        $this->smsDispatcher->dispatch($order, $toStatus, $user);
    }

    /**
     * Regla forward-only del kanban. Una orden solo puede avanzar (rank destino
     * mayor que rank actual) o quedarse igual. Volver a un estado anterior está
     * prohibido — para corregir un estado mal seteado debe cancelarse la orden
     * y crear una nueva (trazabilidad DIAN). Estados terminales bloquean
     * cualquier transición posterior.
     */
    private function assertForwardOnlyTransition(string $from, string $to): void
    {
        $terminalFailure = (array) config('orders.terminal_failure');
        if (in_array($from, $terminalFailure, true)) {
            throw ValidationException::withMessages([
                'status' => 'La orden ya está cerrada (estado: '.$from.') y no admite cambios.',
            ]);
        }

        $ranks = (array) config('orders.kanban_rank');
        $fromRank = $ranks[$from] ?? null;
        $toRank = $ranks[$to] ?? null;

        if ($fromRank === null || $toRank === null) {
            // El estado no está en el kanban (p. ej. completed→refunded): se
            // maneja por endpoints dedicados (refund/cancel), no aquí.
            throw ValidationException::withMessages([
                'status' => 'Transición fuera del kanban; usa el endpoint correspondiente.',
            ]);
        }

        if ($toRank < $fromRank) {
            throw ValidationException::withMessages([
                'status' => 'Las órdenes solo avanzan en el tablero; no se puede regresar de "'.$from.'" a "'.$to.'".',
            ]);
        }
    }

    /**
     * Marca como `served` los `order_items` que sigan abiertos en cocina
     * (approved | in_kitchen | ready) cuando la orden se cierra. Así dejan de
     * aparecer en el KDS y el estado queda consistente con la orden completada.
     *
     * Los tres estados ya son `consumable`, por lo que `orders.total` no cambia.
     * NO toca `pending_approval` (no consumable) ni `cancelled`.
     */
    private function markOpenKitchenItemsServed(Order $order): void
    {
        OrderItem::query()
            ->where('order_id', $order->id)
            ->whereIn('status', (array) config('orders.item_statuses.operational'))
            ->update([
                'status' => 'served',
                'served_at' => now(),
            ]);
    }

    /**
     * Marca como pagados (paid_at + paid_receipt_id) los items no cancelados
     * que aún no tengan pago, cuando un receipt cubre el TOTAL de la orden
     * (closeWithPayment y los cierres offline). Es el espejo del stamping por
     * item de TableCashierService::payPartial/payAll — sin él, el cobro de
     * mesa seguía mostrando esos items como pendientes (doble cobro).
     */
    public function markOrderItemsPaid(Order $order, string $receiptId, Carbon $paidAt): void
    {
        OrderItem::query()
            ->where('order_id', $order->id)
            ->whereNull('paid_at')
            ->where('status', '!=', 'cancelled')
            ->update([
                'paid_at' => $paidAt,
                'paid_receipt_id' => $receiptId,
            ]);
    }

    /**
     * Sincroniza los `order_items` con el nuevo estado de la orden cuando esta
     * avanza en el tablero (/orders/board), de modo que el KDS (/kds) refleje
     * la misma columna. Es la contraparte de `maybePromoteOrderStatus`
     * (KdsTicketService), que propaga en el sentido inverso (item → orden).
     *
     * Forward-only: nunca regresa un item a un estado anterior — solo empuja a
     * los que vienen "atrás" del estado destino. Todos los estados tocados son
     * `consumable` (o pasan a `served`, también consumable), así que
     * `orders.total` no cambia. No toca `pending_approval` ni `cancelled`.
     *
     * Mapa orden → item:
     *   in_kitchen           → approved              ⇒ in_kitchen
     *   ready                → approved | in_kitchen ⇒ ready
     *   in_transit/completed → approved|in_kitchen|ready ⇒ served (sale del KDS)
     */
    private function syncItemsToOrderStatus(Order $order, string $target): void
    {
        $now = now();

        switch ($target) {
            case 'in_kitchen':
                OrderItem::query()
                    ->where('order_id', $order->id)
                    ->where('status', 'approved')
                    ->update(['status' => 'in_kitchen', 'in_kitchen_at' => $now]);
                break;

            case 'ready':
                OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereIn('status', ['approved', 'in_kitchen'])
                    ->update([
                        'status' => 'ready',
                        // Si el item nunca pasó por cocina (approved → ready
                        // directo), dejamos in_kitchen_at coherente para el SLA.
                        'in_kitchen_at' => DB::raw('COALESCE(in_kitchen_at, NOW())'),
                        'ready_at' => $now,
                    ]);
                break;

            case 'in_transit':
            case 'completed':
                $this->markOpenKitchenItemsServed($order);
                break;
        }
    }
}
