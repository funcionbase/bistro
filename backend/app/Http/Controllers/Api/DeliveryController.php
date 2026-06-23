<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\AssignCourierRequest;
use App\Http\Requests\Delivery\CompleteDeliveryRequest;
use App\Http\Requests\Delivery\StoreDeliveryRequest;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Rules\SafePlainText;
use App\Services\DeliveryService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona los domicilios de pedidos: asignación de repartidor, completado, cancelación y listado.
 *
 * Un pedido solo puede tener un domicilio activo (status=pending) a la vez; el controlador lo verifica.
 * complete(): solo aplica a domicilios en estado pending; calcula duration_minutes automáticamente.
 * destroy(): usa SoftDelete via cancelDelivery(); no elimina físicamente el registro.
 * Soporta paginación cursor (paginate=cursor) y offset estándar.
 * Configurable en config/delivery.php: max_active_per_courier, reassign_reasons.
 */
class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveryService,
        private readonly FeaturePermissionService $permissionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $maxPageSize = (int) config('mobile.api_max_page_size', 100);
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$maxPageSize],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:30'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'paginate' => ['nullable', 'string', 'in:cursor,offset'],
            'cursor' => ['nullable', 'string', 'max:255'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? config('mobile.api_default_page_size', 20));

        $query = Delivery::forCompany($companyNit)
            ->with(['order', 'deliverer', 'creator'])
            ->when($validated['user_id'] ?? null, fn ($q, $v) => $q->forUser((int) $v))
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($validated['date_from'] ?? null, fn ($q, $v) => $q->where('assigned_at', '>=', $v))
            ->when($validated['date_to'] ?? null, fn ($q, $v) => $q->where('assigned_at', '<=', $v))
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');

        if (($validated['paginate'] ?? null) === 'cursor') {
            $paginated = $query->cursorPaginate($perPage, ['*'], 'cursor', $validated['cursor'] ?? null);

            return response()->json([
                'data' => $paginated->items(),
                'pagination' => [
                    'per_page' => $paginated->perPage(),
                    'next_cursor' => $paginated->nextCursor()?->encode(),
                    'prev_cursor' => $paginated->previousCursor()?->encode(),
                    'has_more' => $paginated->hasMorePages(),
                ],
            ]);
        }

        return response()->json($query->paginate($perPage));
    }

    public function getCouriers(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'read');

        $companyNit = $request->attributes->get('active_company_nit');
        $company = Company::where('nit', $companyNit)->firstOrFail();

        // Filtra los couriers por acceso a la sede activa (branch_users), con
        // bypass para roles is_system. Null en vista consolidada → sin filtro.
        $activeBranchId = $request->attributes->get('active_branch_id');
        $couriers = $this->deliveryService->getCouriers($company, $activeBranchId);

        return response()->json(['data' => $couriers]);
    }

    public function assignCourier(AssignCourierRequest $request, string $orderId): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'create');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $order = Order::forCompany($companyNit)->findOrFail($orderId);
        $deliverer = User::findOrFail($request->user_id);
        $assignedBy = User::findOrFail($jwtPayload['sub']);

        if (! in_array($order->status, ['ready', 'in_transit'])) {
            return response()->json(['message' => 'La orden no está lista para asignación.'], 422);
        }

        if ($order->delivery()->whereIn('status', ['pending'])->exists()) {
            return response()->json(['message' => 'Esta orden ya tiene una entrega activa.'], 422);
        }

        $delivery = $this->deliveryService->assignDeliverer(
            $order,
            $deliverer,
            $assignedBy,
            $request->input('reason'),
        );

        return response()->json(['data' => $delivery], 201);
    }

    public function store(StoreDeliveryRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'create');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $order = Order::forCompany($companyNit)->findOrFail($request->order_id);
        $deliverer = User::findOrFail($request->user_id);
        $assignedBy = User::findOrFail($jwtPayload['sub']);

        if (! in_array($order->status, ['ready', 'in_transit'])) {
            return response()->json(['message' => 'La orden no está lista para asignación.'], 422);
        }

        if ($order->delivery()->whereIn('status', ['pending'])->exists()) {
            return response()->json(['message' => 'Esta orden ya tiene una entrega activa.'], 422);
        }

        $delivery = $this->deliveryService->assignDeliverer(
            $order,
            $deliverer,
            $assignedBy,
            $request->input('reason'),
        );

        return response()->json(['data' => $delivery], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $delivery = Delivery::forCompany($companyNit)
            ->with(['order', 'deliverer', 'creator', 'previousDelivery.deliverer'])
            ->findOrFail($id);

        return response()->json(['data' => $delivery]);
    }

    public function complete(CompleteDeliveryRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $delivery = Delivery::forCompany($companyNit)->with('order')->findOrFail($id);

        if (! $delivery->isPending()) {
            return response()->json(['message' => 'La entrega no está en estado pendiente.'], 422);
        }

        if ($delivery->order?->status === 'completed') {
            return response()->json(['message' => 'Orden completada, no editable.'], 409);
        }

        $completedBy = User::findOrFail($jwtPayload['sub']);
        $delivery = $this->deliveryService->completeDelivery($delivery, $completedBy);

        return response()->json(['data' => $delivery]);
    }

    public function getReassignReasons(): JsonResponse
    {
        $reasons = collect(config('delivery.reassign_reasons', []))
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values();

        return response()->json(['data' => $reasons]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'delete');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $delivery = Delivery::forCompany($companyNit)->with('order')->findOrFail($id);

        if (! $delivery->isPending()) {
            return response()->json(['message' => 'Solo se pueden cancelar entregas pendientes.'], 422);
        }

        if ($delivery->order?->status === 'completed') {
            return response()->json(['message' => 'Orden completada, no editable.'], 409);
        }

        $cancelledBy = User::findOrFail($jwtPayload['sub']);
        $reason = $request->input('reason', 'Cancelada por el administrador');

        $this->deliveryService->cancelDelivery($delivery, $reason, $cancelledBy);

        return response()->json(['deleted' => true]);
    }

    /**
     * Mis entregas (#119). Lista deliveries con `user_id = actor` en la
     * sede activa. Pestañas: asignadas (`pending`), completadas hoy,
     * canceladas hoy. Sin paginación pesada — el courier típico maneja
     * <20 entregas por turno.
     */
    public function mine(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'read');

        $payload = $request->attributes->get('jwt_payload');
        $userId = (string) ($payload['sub'] ?? '');
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,completed,cancelled'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query = Delivery::forCompany($companyNit)
            ->forUser($userId)
            ->with(['order:id,client_phone,delivery_address,total,status,branch_id,table_number', 'deliverer:id,name'])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['date_from'])) {
            $query->where('assigned_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->where('assigned_at', '<=', $validated['date_to']);
        }

        $deliveries = $query->limit(100)->get();

        return response()->json(['data' => $deliveries]);
    }

    /**
     * Bolsa de órdenes disponibles para auto-asignación (#119). Devuelve
     * órdenes con `order_type='delivery'`, `status` en {ready, in_kitchen}
     * y SIN delivery pending activo, en la sede activa del actor.
     *
     * El BranchScope natural de Order ya filtra por sede. Se excluyen
     * órdenes que ya tienen delivery pending (subquery EXISTS para evitar
     * N+1) y las que ya tienen receipt (caso edge: orden lista para retirar
     * sin domicilio).
     */
    public function available(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'self_assign');

        $orders = Order::query()
            ->where('order_type', 'delivery')
            ->whereIn('status', ['ready', 'in_kitchen'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('deliveries')
                    ->whereColumn('deliveries.order_id', 'orders.id')
                    ->where('deliveries.status', 'pending')
                    ->whereNull('deliveries.deleted_at');
            })
            ->select(['id', 'client_phone', 'delivery_address', 'total', 'status', 'branch_id', 'table_number', 'ordered_at'])
            ->orderBy('ordered_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $orders]);
    }

    /**
     * El domiciliario se auto-asigna una orden disponible (#119).
     *
     * Throttle aplicado a nivel de ruta. La carrera se resuelve por
     * `Order::lockForUpdate` dentro de `DeliveryService::selfAssign`.
     */
    public function selfAssign(Request $request, string $orderId): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'self_assign');

        $payload = $request->attributes->get('jwt_payload');
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $activeBranchId = (string) $request->attributes->get('active_branch_id');

        $order = Order::query()
            ->where('id', $orderId)
            ->where('company_nit', $companyNit)
            ->firstOrFail();

        if ($order->branch_id !== $activeBranchId) {
            return response()->json([
                'message' => 'Esta orden no pertenece a tu sede activa.',
                'code' => 'ORDER_OTHER_BRANCH',
            ], 403);
        }

        $courier = User::findOrFail($payload['sub']);

        $delivery = $this->deliveryService->selfAssign($order, $courier);

        return response()->json(['data' => $delivery], 201);
    }

    /**
     * Revertir delivery completado por error (#119). Solo el courier
     * propio o un admin con `deliveries.update`. Bloquea con receipt.
     */
    public function revert(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'update');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $payload = $request->attributes->get('jwt_payload');
        $actorId = (string) ($payload['sub'] ?? '');
        $isAdmin = (bool) ($payload['role']['is_system'] ?? false);

        $delivery = Delivery::forCompany($companyNit)->with('order')->findOrFail($id);

        if (! $isAdmin && $delivery->user_id !== $actorId) {
            return response()->json([
                'message' => 'Solo el domiciliario asignado o un admin pueden revertir esta entrega.',
                'code' => 'DELIVERY_NOT_OWNED',
            ], 403);
        }

        $actor = User::findOrFail($actorId);

        $delivery = $this->deliveryService->revertDelivery($delivery, $actor);

        return response()->json(['data' => $delivery]);
    }

    /**
     * El cliente rechazó la entrega (#119). Cancela delivery + orden,
     * bloquea con receipt. Solo courier propio o admin.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'update');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $payload = $request->attributes->get('jwt_payload');
        $actorId = (string) ($payload['sub'] ?? '');
        $isAdmin = (bool) ($payload['role']['is_system'] ?? false);

        $delivery = Delivery::forCompany($companyNit)->with('order')->findOrFail($id);

        if (! $isAdmin && $delivery->user_id !== $actorId) {
            return response()->json([
                'message' => 'Solo el domiciliario asignado o un admin pueden marcar como rechazado.',
                'code' => 'DELIVERY_NOT_OWNED',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
        ]);

        $actor = User::findOrFail($actorId);

        $delivery = $this->deliveryService->rejectDelivery($delivery, $validated['reason'], $actor);

        return response()->json(['data' => $delivery]);
    }
}
