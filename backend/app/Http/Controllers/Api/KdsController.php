<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\KdsStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\RestaurantMenu;
use App\Models\Table;
use App\Models\TableSessionGuest;
use App\Models\User;
use App\Services\KdsTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Kitchen Display System — API de la pantalla de cocina.
 *
 * Dos modos de operación:
 *
 *  1. **Consolidado (JWT)** — `index/markInKitchen/markReady/markServed`.
 *     Cocinero o supervisor entra con su usuario
 *     y ve todos los items de la sede en estados activos. Tickets =
 *     order_items (no orden completa).
 *
 *  2. **Por estación (device-token)** — `indexForStation` +
 *     `transitionForStation*`. La tableta presenta su device-token
 *     (`Authorization: Bearer` o cookie `kds_device_token`); el middleware
 *     `kds.device` inyecta `active_station_id` y `active_station_slug`.
 *     Sólo se devuelven items cuya categoría mapea a esa estación (o que
 *     no declaran categoría y caen al `is_default`). Agrupados por orden,
 *     ordenados FIFO con SLA calculado server-side.
 *
 * Aislamiento por sede: `BranchScope` ya filtra cuando hay
 * `active_branch_id` en el request; tanto el JWT como el device-token lo
 * inyectan. En `indexForStation` no se restringe a órdenes con
 * `table_session_id` (a diferencia del modo consolidado heredado): la
 * cocina debe ver mesas, domicilios y takeout por igual cuando opera en
 * modo "estación pura".
 */
class KdsController extends Controller
{
    public function __construct(private readonly KdsTicketService $kds) {}

    /**
     * Lista los tickets activos para cocina: items con status
     * approved | in_kitchen | ready. Modo consolidado (JWT).
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        $statusFilter = $request->query('status');
        $tableId = $request->query('table_id');

        $allowed = ['approved', 'in_kitchen', 'ready'];
        $statuses = is_string($statusFilter) && in_array($statusFilter, $allowed, true)
            ? [$statusFilter]
            : $allowed;

        // Solo órdenes aún abiertas: una orden completada/cancelada/devuelta no
        // debe seguir mostrando tickets en cocina aunque sus items quedaran en
        // `ready` (p. ej. si se completó desde /orders/board). Excluimos los
        // estados terminales en vez de incluir solo operacionales para no
        // ocultar `pending_approval` ni estados nuevos.
        $closedStatuses = array_merge(
            (array) config('orders.terminal_success'),
            (array) config('orders.terminal_failure'),
        );

        $orderIds = Order::query()
            ->withoutGlobalScopes()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereNotIn('status', $closedStatuses)
            ->pluck('id');

        $items = OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', $statuses)
            ->orderBy('in_kitchen_at')
            ->orderBy('id')
            ->get();

        // Pre-cargar guests y tablas asociadas para evitar N+1.
        $guestIds = $items->pluck('guest_id')->filter()->unique()->values();
        $guests = TableSessionGuest::query()
            ->whereIn('id', $guestIds)
            ->with('session')
            ->get()
            ->keyBy('id');

        $sessionIds = $guests->pluck('table_session_id')->unique()->values();
        $tableIds = $guests->pluck('session.table_id')->filter()->unique()->values();
        $tables = Table::withoutBranchScope()
            ->whereIn('id', $tableIds)
            ->get()
            ->keyBy('id');

        if (is_string($tableId) && $tableId !== '') {
            $filteredItems = $items->filter(function (OrderItem $item) use ($guests, $tableId) {
                $guest = $guests->get($item->guest_id);
                if ($guest === null) {
                    return false;
                }

                return (string) $guest->session?->table_id === (string) $tableId;
            })->values();
            $items = $filteredItems;
        }

        // Notas grupales / kitchen_alert por orden (replicadas en cada ticket).
        $orderNotes = OrderNote::query()
            ->whereIn('order_id', $items->pluck('order_id')->unique()->values())
            ->whereIn('scope', ['group', 'kitchen_alert'])
            ->get()
            ->groupBy('order_id');

        $tickets = $items->map(function (OrderItem $item) use ($guests, $tables, $orderNotes) {
            $guest = $guests->get($item->guest_id);
            $session = $guest?->session;
            $table = $session ? $tables->get($session->table_id) : null;
            $notes = $orderNotes->get($item->order_id, collect());

            return [
                'id' => $item->id,
                'order_id' => $item->order_id,
                'name' => $item->name,
                'quantity' => (int) $item->quantity,
                'notes' => $item->notes,
                'status' => $item->status,
                'approved_at' => optional($item->approved_at)?->toIso8601String(),
                'in_kitchen_at' => optional($item->in_kitchen_at)?->toIso8601String(),
                'ready_at' => optional($item->ready_at)?->toIso8601String(),
                'guest' => $guest ? [
                    'id' => $guest->id,
                    'display_name' => $guest->display_name,
                ] : null,
                'table' => $table ? [
                    'id' => $table->id,
                    'number' => $table->number,
                ] : null,
                'order_notes' => $notes->map(fn (OrderNote $n) => [
                    'id' => $n->id,
                    'scope' => $n->scope,
                    'body' => $n->body,
                ])->values(),
            ];
        })->values();
        unset($sessionIds);

        return response()->json(['data' => $tickets]);
    }

    public function markInKitchen(Request $request, string $itemId): JsonResponse
    {
        return $this->transition($request, $itemId, 'markInKitchen');
    }

    public function markReady(Request $request, string $itemId): JsonResponse
    {
        return $this->transition($request, $itemId, 'markReady');
    }

    public function markServed(Request $request, string $itemId): JsonResponse
    {
        return $this->transition($request, $itemId, 'markServed');
    }

    /**
     * Tickets filtrados por estación, agrupados por orden, con SLA
     * calculado server-side. Acceso vía device-token (`kds.device`).
     *
     * El device-token determina la estación; el `stationSlug` de la URL es
     * informativo (el middleware ya validó que coincide con la del token).
     * La sede activa también la inyecta el middleware.
     */
    public function indexForStation(Request $request, string $stationSlug): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $branchId = (string) $request->attributes->get('active_branch_id');
        $stationId = (string) $request->attributes->get('active_station_id');

        $station = KdsStation::query()->whereKey($stationId)->firstOrFail();

        $defaultStationId = $this->resolveDefaultStationId($companyNit, $branchId);
        $stationMap = $this->resolveActiveMenuStationMap($companyNit, $branchId);

        // Sin estación `is_default=true` en la sede, los items de
        // categorías sin mapeo desaparecen del KDS (cocina ciega). Fallar
        // explícito para forzar al admin a marcar una default.
        if ($defaultStationId === null) {
            return response()->json([
                'message' => 'La sede no tiene una estación default configurada. Pídele al admin que marque una en /company/kds.',
            ], 409);
        }

        // Items en estados activos del KDS para la sede activa. La sede ya
        // viene como atributo del request; OrderItem no usa BranchScope (lo
        // hace su Order parent), así que filtramos via order_id.
        $orderIds = Order::query()
            ->withoutGlobalScopes()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['pending', 'in_kitchen', 'ready'])
            ->pluck('id');

        // 1) Cargar todos los items activos de las órdenes operativas de la
        // sede — necesitamos el contexto completo de cada orden para que el
        // cocinero sepa qué más se está preparando (ensalada en fría, bebida
        // en barra, etc.) aunque solo opere su estación.
        $allItems = OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', ['approved', 'in_kitchen', 'ready', 'served'])
            ->orderBy('approved_at')
            ->orderBy('id')
            ->get();

        // 2) Identificar qué órdenes tienen al menos UN item operable en
        // esta estación con status approved|in_kitchen|ready. Las órdenes
        // que solo tienen items servidos o de otras estaciones no aparecen.
        $orderIdsWithStationWork = $allItems->filter(function (OrderItem $item) use ($stationMap, $stationId, $defaultStationId) {
            if (! in_array($item->status, ['approved', 'in_kitchen', 'ready'], true)) {
                return false;
            }
            $explicit = $stationMap[(string) $item->menu_item_id] ?? null;
            $effective = $explicit ?? $defaultStationId;

            return $effective === $stationId;
        })->pluck('order_id')->unique()->values()->all();

        // 3) Para cada orden con trabajo en la estación, traer TODOS sus
        // items activos (ready/served incluidos) para dar contexto al
        // cocinero. Cada item marca `is_own_station` para que la UI
        // distinga lo operable (CTAs activos) de lo ajeno (informativo).
        $filtered = $allItems->whereIn('order_id', $orderIdsWithStationWork)->values();

        // Pre-cargar guests/tables (mismas estructuras del modo consolidado).
        $guestIds = $filtered->pluck('guest_id')->filter()->unique()->values();
        $guests = TableSessionGuest::query()
            ->whereIn('id', $guestIds)
            ->with('session')
            ->get()
            ->keyBy('id');
        $tableIds = $guests->pluck('session.table_id')->filter()->unique()->values();
        $tables = Table::withoutBranchScope()
            ->whereIn('id', $tableIds)
            ->get()
            ->keyBy('id');

        $orderNotes = OrderNote::query()
            ->whereIn('order_id', $filtered->pluck('order_id')->unique()->values())
            ->whereIn('scope', ['group', 'kitchen_alert'])
            ->get()
            ->groupBy('order_id');

        $now = Carbon::now();

        // Agrupación por orden. Cada grupo expone meta (mesa, comensal,
        // notas) + lista de items. El SLA del grupo es el más severo de sus
        // items (rojo > ámbar > verde).
        $grouped = $filtered
            ->groupBy('order_id')
            ->map(function ($groupItems, $orderId) use ($guests, $tables, $orderNotes, $station, $now) {
                $first = $groupItems->first();
                $guest = $guests->get($first->guest_id);
                $session = $guest?->session;
                $table = $session ? $tables->get($session->table_id) : null;
                $notes = $orderNotes->get($orderId, collect());

                $items = $groupItems->map(function (OrderItem $item) use ($station, $now, $stationMap, $defaultStationId) {
                    $explicit = $stationMap[(string) $item->menu_item_id] ?? null;
                    $effective = $explicit ?? $defaultStationId;
                    $isOwn = $effective === $station->id;

                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'quantity' => (int) $item->quantity,
                        'notes' => $item->notes,
                        'status' => $item->status,
                        'approved_at' => optional($item->approved_at)?->toIso8601String(),
                        'in_kitchen_at' => optional($item->in_kitchen_at)?->toIso8601String(),
                        'ready_at' => optional($item->ready_at)?->toIso8601String(),
                        // SLA solo cuenta para items de la estación. Items
                        // ajenos sirven de contexto y no presionan el semáforo.
                        'sla_state' => $isOwn ? $this->computeSlaState($item, $station, $now) : 'green',
                        // Contexto de orden completa: cocinero ve qué
                        // más lleva la mesa aunque solo opere lo suyo.
                        'is_own_station' => $isOwn,
                        'station_id' => $effective,
                    ];
                })->values();

                // El SLA del grupo solo considera items propios — un ticket
                // de caliente con su hamburguesa en rojo + ensalada de fría
                // en verde sigue siendo rojo para esta pantalla.
                $ownItems = $items->filter(fn (array $i) => $i['is_own_station'] === true);
                $worstSla = $this->worstSla($ownItems->pluck('sla_state')->all());

                return [
                    'order_id' => $orderId,
                    'guest' => $guest ? [
                        'id' => $guest->id,
                        'display_name' => $guest->display_name,
                    ] : null,
                    'table' => $table ? [
                        'id' => $table->id,
                        'number' => $table->number,
                    ] : null,
                    'order_notes' => $notes->map(fn (OrderNote $n) => [
                        'id' => $n->id,
                        'scope' => $n->scope,
                        'body' => $n->body,
                    ])->values(),
                    'items' => $items,
                    'oldest_approved_at' => $groupItems->min('approved_at')?->toIso8601String(),
                    'sla_state' => $worstSla,
                ];
            })
            ->sortBy(function (array $group) {
                $weight = match ($group['sla_state']) {
                    'red' => 0,
                    'amber' => 1,
                    default => 2,
                };

                return sprintf('%d-%s', $weight, $group['oldest_approved_at'] ?? '9999');
            })
            ->values();

        return response()->json([
            'station' => [
                'id' => $station->id,
                'slug' => $station->slug,
                'name' => $station->name,
                'color' => $station->color,
                'sla_warn_minutes' => $station->sla_warn_minutes,
                'sla_alert_minutes' => $station->sla_alert_minutes,
            ],
            'data' => $grouped,
        ]);
    }

    public function markInKitchenForStation(Request $request, string $stationSlug, string $itemId): JsonResponse
    {
        return $this->transitionForStation($request, $itemId, 'markInKitchen');
    }

    public function markReadyForStation(Request $request, string $stationSlug, string $itemId): JsonResponse
    {
        return $this->transitionForStation($request, $itemId, 'markReady');
    }

    public function markServedForStation(Request $request, string $stationSlug, string $itemId): JsonResponse
    {
        return $this->transitionForStation($request, $itemId, 'markServed');
    }

    private function transition(Request $request, string $itemId, string $method): JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');

        $item = OrderItem::query()
            ->whereKey($itemId)
            ->whereHas('order', function ($q) use ($companyNit, $branchId) {
                $q->withoutGlobalScopes()
                    ->where('company_nit', $companyNit)
                    ->where('branch_id', $branchId);
            })
            ->firstOrFail();

        $sub = $request->attributes->get('jwt_payload')['sub'] ?? null;
        /** @var User $user */
        $user = User::query()->findOrFail($sub);

        try {
            $updated = $this->kds->{$method}($item, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'item' => [
                'id' => $updated->id,
                'status' => $updated->status,
                'in_kitchen_at' => optional($updated->in_kitchen_at)?->toIso8601String(),
                'ready_at' => optional($updated->ready_at)?->toIso8601String(),
                'served_at' => optional($updated->served_at)?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Versión device-token de `transition`: además de validar sede del
     * token, valida que el item pertenezca a la estación del token (un
     * token de Caliente no debe poder marcar listos items de Fría).
     */
    private function transitionForStation(Request $request, string $itemId, string $method): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $branchId = (string) $request->attributes->get('active_branch_id');
        $stationId = (string) $request->attributes->get('active_station_id');

        $item = OrderItem::query()
            ->whereKey($itemId)
            ->whereHas('order', function ($q) use ($companyNit, $branchId) {
                $q->withoutGlobalScopes()
                    ->where('company_nit', $companyNit)
                    ->where('branch_id', $branchId);
            })
            ->firstOrFail();

        $defaultStationId = $this->resolveDefaultStationId($companyNit, $branchId);
        $stationMap = $this->resolveActiveMenuStationMap($companyNit, $branchId);
        $effective = $stationMap[(string) $item->menu_item_id] ?? $defaultStationId;

        if ($effective !== $stationId) {
            return response()->json([
                'message' => 'Este item no pertenece a la estación de tu dispositivo.',
            ], 403);
        }

        // El device-token no representa un user. Para el audit log usamos
        // un User sintético (system) o el creador del token. Por ahora
        // dejamos $actor null vía el patrón de KdsTicketService — pero el
        // service requiere User. Compromiso v1: usar el último User que
        // generó el token (audit `kds.device_token.generated` lo registra).
        // Si necesitamos auditar al token directamente, podemos cambiar la
        // firma de KdsTicketService::transition() para aceptar nullable.
        // En v1 buscamos un owner de la empresa como fallback.
        $user = $this->resolveDeviceActor($companyNit);

        try {
            $updated = $this->kds->{$method}($item, $user, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($method === 'markReady') {
            $station = KdsStation::query()->whereKey($stationId)->first();
            if ($station !== null) {
                $this->kds->maybeMarkStationReady(
                    orderId: $updated->order_id,
                    station: $station,
                    stationMap: $stationMap,
                    defaultStationId: $defaultStationId,
                    actor: $user,
                    request: $request,
                );
            }
        }

        return response()->json([
            'item' => [
                'id' => $updated->id,
                'status' => $updated->status,
                'in_kitchen_at' => optional($updated->in_kitchen_at)?->toIso8601String(),
                'ready_at' => optional($updated->ready_at)?->toIso8601String(),
                'served_at' => optional($updated->served_at)?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Resuelve el id de la estación `is_default=true` de la sede activa.
     * Cached per request via memoization en el container del request.
     */
    private function resolveDefaultStationId(string $companyNit, string $branchId): ?string
    {
        return KdsStation::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereNull('archived_at')
            ->where('is_default', true)
            ->value('id');
    }

    /**
     * Devuelve `menu_item_id → kds_station_id|null` del menú activo de la
     * sede. Si no hay menú activo, devuelve mapa vacío (todo cae al
     * fallback).
     *
     * @return array<string, string|null>
     */
    private function resolveActiveMenuStationMap(string $companyNit, string $branchId): array
    {
        $menu = RestaurantMenu::query()
            ->withoutGlobalScopes()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->first();

        return $menu?->menuItemStationMap() ?? [];
    }

    /**
     * SLA por item: tiempo transcurrido desde `approved_at` (o `in_kitchen_at`
     * si ya entró a cocina) contra los umbrales de la estación.
     */
    private function computeSlaState(OrderItem $item, KdsStation $station, Carbon $now): string
    {
        $since = $item->in_kitchen_at ?? $item->approved_at;
        if ($since === null) {
            return 'green';
        }

        $elapsedMinutes = $since->diffInMinutes($now);

        if ($elapsedMinutes >= $station->sla_alert_minutes) {
            return 'red';
        }
        if ($elapsedMinutes >= $station->sla_warn_minutes) {
            return 'amber';
        }

        return 'green';
    }

    /**
     * @param  list<string>  $states
     */
    private function worstSla(array $states): string
    {
        if (in_array('red', $states, true)) {
            return 'red';
        }
        if (in_array('amber', $states, true)) {
            return 'amber';
        }

        return 'green';
    }

    /**
     * Para el modo device-token (sin sesión web), el actor del audit es el
     * primer owner de la empresa (rol is_system). Solución v1; F8 puede
     * abrir el modelo a un audit más rico ("device:label" como subject).
     *
     * Si la empresa no tiene owner activo (caso degenerado — el
     * enrollment lo crea siempre), lanza 409 antes de explotar con
     * findOrFail.
     */
    private function resolveDeviceActor(string $companyNit): User
    {
        $userId = CompanyUser::query()
            ->where('company_nit', $companyNit)
            ->whereHas('role', fn ($q) => $q->where('is_system', true))
            ->orderBy('id')
            ->value('user_id');

        if ($userId === null) {
            abort(409, 'La empresa no tiene un usuario administrador activo para registrar la operación del KDS.');
        }

        return User::query()->findOrFail($userId);
    }
}
