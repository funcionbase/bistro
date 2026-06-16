<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Delivery;
use App\Models\DeliveryStatusLog;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona el ciclo de vida de los domicilios: asignación, completado, cancelación y reasignación.
 *
 * Invariante de domicilio único: reassignDeliverer() cancela el domicilio actual antes de crear uno nuevo.
 * El campo duration_minutes se calcula al completar (markAsDelivered), no al crear.
 * Todas las operaciones de estado se ejecutan en transacción DB y registran en auditoría.
 * Cada transición además persiste una fila en `delivery_status_logs` (#119) para
 * reconstruir historia con un único índice O(1) por `delivery_id`.
 * Notificaciones al cliente: WhatsApp vía DeliveryNotificationService.
 *
 * Configurable en config/delivery.php: max_active_per_courier, notify_on_assignment,
 * notify_on_completion, share_courier_phone, whatsapp_api_key.
 */
class DeliveryService
{
    /** Razones estructuradas (#119). Valores tipo enum sincronizados con la BD. */
    public const REASON_ERROR_USUARIO = 'error_usuario';

    public const REASON_PEDIDO_RECHAZADO = 'pedido_rechazado';

    public const REASON_REASSIGNED = 'reassigned';

    public function __construct(
        private readonly DeliveryNotificationService $notificationService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Loguea una transición en `delivery_status_logs`. Append-only. Se invoca
     * desde cada método mutador del service para mantener un único punto de
     * escritura — los controllers NO deben escribir directamente esta tabla.
     */
    private function logStatusChange(
        Delivery $delivery,
        string $fromStatus,
        string $toStatus,
        ?string $reason,
        ?User $actor,
    ): void {
        DeliveryStatusLog::create([
            'company_nit' => $delivery->company_nit,
            'branch_id' => $delivery->branch_id,
            'delivery_id' => $delivery->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * ¿La orden ya tiene comprobante de pago registrado? Bloquea revert y
     * reject por inmutabilidad contable DIAN — el delivery no puede volver
     * a un estado previo si ya generó receipt (eso requeriría un refund,
     * que es decisión de admin, no del courier).
     */
    private function orderHasPaymentReceipt(string $orderId): bool
    {
        return PaymentReceipt::withoutBranchScope()
            ->where('order_id', $orderId)
            ->exists();
    }

    public function assignDeliverer(
        Order $order,
        User $deliverer,
        User $assignedBy,
        ?string $reason = null
    ): Delivery {
        $this->assertCourierCapacity($deliverer, $order->company_nit);

        return DB::transaction(function () use ($order, $deliverer, $assignedBy, $reason) {
            $previousOrderStatus = (string) $order->status;

            $delivery = Delivery::create([
                'company_nit' => $order->company_nit,
                'order_id' => $order->id,
                'user_id' => $deliverer->id,
                'assigned_at' => now(),
                'status' => 'pending',
                'reason' => $reason ?? 'Asignación inicial',
                'created_by' => $assignedBy->id,
            ]);

            $order->update(['status' => 'in_transit']);

            $this->logStatusChange($delivery, 'none', 'pending', null, $assignedBy);

            $this->auditService->log('delivery.assigned', $assignedBy, $delivery, [
                'order_id' => $order->id,
                'deliverer_id' => $deliverer->id,
                'deliverer_name' => $deliverer->name,
                'previous_order_status' => $previousOrderStatus,
            ]);

            $this->notificationService->notifyClientAssignment($order, $delivery);

            return $delivery->load(['order', 'deliverer']);
        });
    }

    /**
     * Auto-asignación del domiciliario sobre una orden disponible (#119).
     *
     * Reglas:
     *  - La orden debe pertenecer a la misma sede activa del courier.
     *  - Bajo `Order::lockForUpdate` para descartar carreras entre dos
     *    couriers concurrentes (el segundo recibe ValidationException).
     *  - El partial unique index `deliveries_active_order_unique` también
     *    actúa como cinturón a nivel BD.
     *
     * @throws HttpResponseException 409 si la orden ya tiene delivery activo.
     */
    public function selfAssign(Order $order, User $courier): Delivery
    {
        $this->assertCourierCapacity($courier, $order->company_nit);

        return DB::transaction(function () use ($order, $courier) {
            // Lock pesimista sobre la orden + verificación de no-delivery
            // activo dentro de la transacción.
            $lockedOrder = Order::withoutBranchScope()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($lockedOrder === null) {
                throw new HttpResponseException(
                    response()->json([
                        'message' => 'La orden no existe o ya no está disponible.',
                        'code' => 'ORDER_NOT_FOUND',
                    ], 404)
                );
            }

            $hasActive = Delivery::withoutBranchScope()
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'pending')
                ->exists();

            if ($hasActive) {
                throw new HttpResponseException(
                    response()->json([
                        'message' => 'Esta orden ya fue tomada por otro domiciliario.',
                        'code' => 'ORDER_ALREADY_TAKEN',
                    ], 409)
                );
            }

            $delivery = Delivery::create([
                'company_nit' => $lockedOrder->company_nit,
                'branch_id' => $lockedOrder->branch_id,
                'order_id' => $lockedOrder->id,
                'user_id' => $courier->id,
                'assigned_at' => now(),
                'status' => 'pending',
                'reason' => 'Auto-asignación',
                'created_by' => $courier->id,
            ]);

            $previousOrderStatus = (string) $lockedOrder->status;
            $lockedOrder->update(['status' => 'in_transit']);

            $this->logStatusChange($delivery, 'none', 'pending', null, $courier);

            $this->auditService->log('delivery.self_assigned', $courier, $delivery, [
                'order_id' => $lockedOrder->id,
                'previous_order_status' => $previousOrderStatus,
            ]);

            $this->notificationService->notifyClientAssignment($lockedOrder, $delivery);

            return $delivery->load(['order', 'deliverer']);
        });
    }

    public function completeDelivery(Delivery $delivery, User $completedBy): Delivery
    {
        return DB::transaction(function () use ($delivery, $completedBy) {
            $fromStatus = (string) $delivery->status;

            $delivery->markAsDelivered();

            $delivery->order->update(['status' => 'completed']);

            $this->logStatusChange($delivery, $fromStatus, 'completed', null, $completedBy);

            $this->auditService->log('delivery.completed', $completedBy, $delivery, [
                'order_id' => $delivery->order_id,
                'deliverer_id' => $delivery->user_id,
                'duration_minutes' => $delivery->duration_minutes,
            ]);

            $this->notificationService->notifyClientDelivered($delivery->order, $delivery);

            return $delivery->refresh();
        });
    }

    /**
     * Revierte un delivery `completed` de vuelta a `pending` cuando el
     * domiciliario lo marcó por error (#119).
     *
     * Bloqueos:
     *  - El delivery debe estar `completed`.
     *  - La orden NO debe tener `payment_receipts` (inmutabilidad DIAN);
     *    si las tiene, 409 con copy claro pidiendo intervención de admin.
     *
     * Tras revert:
     *  - delivery: `status='pending'`, `delivered_at=null`,
     *    `duration_minutes=null`, `status_change_reason='error_usuario'`.
     *  - order: `status='in_transit'`.
     *  - NO se re-notifica al cliente (es corrección interna).
     *
     * @throws HttpResponseException 409 si tiene receipt o no está completed.
     */
    public function revertDelivery(Delivery $delivery, User $actor): Delivery
    {
        if ($delivery->status !== 'completed') {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Solo se puede revertir un domicilio que esté en estado "completado".',
                    'code' => 'DELIVERY_NOT_COMPLETED',
                ], 409)
            );
        }

        if ($this->orderHasPaymentReceipt($delivery->order_id)) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Esta entrega ya tiene un cobro registrado. Pedile a un admin que haga la devolución antes de revertirla.',
                    'code' => 'DELIVERY_HAS_RECEIPT',
                ], 409)
            );
        }

        return DB::transaction(function () use ($delivery, $actor) {
            $delivery->update([
                'status' => 'pending',
                'delivered_at' => null,
                'duration_minutes' => null,
                'status_change_reason' => self::REASON_ERROR_USUARIO,
            ]);

            $delivery->order->update(['status' => 'in_transit']);

            $this->logStatusChange($delivery, 'completed', 'pending', self::REASON_ERROR_USUARIO, $actor);

            $this->auditService->log('delivery.reverted', $actor, $delivery, [
                'order_id' => $delivery->order_id,
                'deliverer_id' => $delivery->user_id,
                'reason' => self::REASON_ERROR_USUARIO,
                'had_receipt' => false,
            ]);

            return $delivery->refresh();
        });
    }

    /**
     * El cliente rechazó la entrega (#119). El delivery pasa a `cancelled`
     * y la orden a `cancelled` (terminal). Bloquea si la orden ya tiene
     * payment_receipt — el dinero salió, hay que pasar por refund admin.
     *
     * Notifica al cliente vía WhatsApp (mismo canal que cancelDelivery).
     *
     * @throws HttpResponseException 409 si la orden tiene receipt.
     */
    public function rejectDelivery(Delivery $delivery, string $reason, User $actor): Delivery
    {
        if (! in_array($delivery->status, ['pending', 'completed'], true)) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'No se puede marcar como rechazado un domicilio en este estado.',
                    'code' => 'DELIVERY_INVALID_STATE',
                ], 409)
            );
        }

        if ($this->orderHasPaymentReceipt($delivery->order_id)) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Esta entrega ya tiene un cobro registrado. Pedile a un admin que haga la devolución.',
                    'code' => 'DELIVERY_HAS_RECEIPT',
                ], 409)
            );
        }

        return DB::transaction(function () use ($delivery, $reason, $actor) {
            $fromStatus = (string) $delivery->status;

            $delivery->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'status_change_reason' => self::REASON_PEDIDO_RECHAZADO,
            ]);

            $delivery->order->update(['status' => 'cancelled']);

            $this->logStatusChange($delivery, $fromStatus, 'cancelled', self::REASON_PEDIDO_RECHAZADO, $actor);

            $this->auditService->log('delivery.rejected', $actor, $delivery, [
                'order_id' => $delivery->order_id,
                'deliverer_id' => $delivery->user_id,
                'reason' => $reason,
                'had_receipt' => false,
            ]);

            // El cliente recibe notificación porque su orden se canceló —
            // el mismo canal que cancelDelivery (admin → cliente).
            $this->notificationService->notifyClientReassignment($delivery->order, $delivery);

            return $delivery->refresh();
        });
    }

    public function cancelDelivery(Delivery $delivery, string $reason, User $cancelledBy): Delivery
    {
        return DB::transaction(function () use ($delivery, $reason, $cancelledBy) {
            $fromStatus = (string) $delivery->status;

            $delivery->markAsCancelled($reason);

            $this->logStatusChange($delivery, $fromStatus, 'cancelled', null, $cancelledBy);

            $this->auditService->log('delivery.cancelled', $cancelledBy, $delivery, [
                'order_id' => $delivery->order_id,
                'reason' => $reason,
            ]);

            return $delivery->refresh();
        });
    }

    public function reassignDeliverer(
        Delivery $currentDelivery,
        User $newDeliverer,
        User $reassignedBy,
        ?string $reason = null
    ): Delivery {
        $this->assertCourierCapacity($newDeliverer, $currentDelivery->company_nit);

        return DB::transaction(function () use ($currentDelivery, $newDeliverer, $reassignedBy, $reason) {
            $oldStatus = (string) $currentDelivery->status;

            $currentDelivery->markAsCancelled('Reasignado a otro repartidor');

            $this->logStatusChange($currentDelivery, $oldStatus, 'cancelled', self::REASON_REASSIGNED, $reassignedBy);

            $newDelivery = Delivery::create([
                'company_nit' => $currentDelivery->company_nit,
                'order_id' => $currentDelivery->order_id,
                'user_id' => $newDeliverer->id,
                'assigned_at' => now(),
                'status' => 'pending',
                'previous_delivery_id' => $currentDelivery->id,
                'reason' => $reason ?? 'Reasignación',
                'created_by' => $reassignedBy->id,
            ]);

            $this->logStatusChange($newDelivery, 'none', 'pending', self::REASON_REASSIGNED, $reassignedBy);

            $this->auditService->log('delivery.reassigned', $reassignedBy, $newDelivery, [
                'order_id' => $currentDelivery->order_id,
                'old_deliverer_id' => $currentDelivery->user_id,
                'new_deliverer_id' => $newDeliverer->id,
                'reason' => $reason,
            ]);

            $this->notificationService->notifyClientReassignment($currentDelivery->order, $newDelivery);

            return $newDelivery->load(['order', 'deliverer']);
        });
    }

    /**
     * IDs de usuarios candidatos a courier en la empresa: miembros cuyo rol
     * concede EXPLÍCITAMENTE el permiso `deliveries.self_assign` (la permisología
     * de courier — ver COURIER_MODE.md).
     *
     * Se filtra por la matriz explícita del rol (`company_role_permissions`),
     * NO por el bypass `is_system`: así un `employee` (is_system=true pero sin el
     * permiso) queda fuera, y entran owner/admin/Domiciliario + cualquier rol
     * custom con el permiso. Excluye cajeros, cocineros, meseros, etc. que no
     * entregan. (Antes los pickers ofrecían a TODOS los miembros activos.)
     *
     * Scope de sede (BK18): si se pasa `$activeBranchId`, además se filtra por
     * acceso a esa sede. Roles `is_system` (owner/admin/employee) bypasean — no
     * tienen filas `branch_users` pero acceden a todas las sedes de la empresa
     * (espejo de `EnsureBranchAccess` + `User::accessibleBranches`). El resto
     * debe tener acceso explícito a la sede activa vía `branch_users`. Si
     * `$activeBranchId` es null (vista consolidada `?branch=all` o sin sede
     * activa), no se aplica filtro de sede.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function deliveryCandidateUserIds(string $companyNit, ?string $activeBranchId = null): \Illuminate\Support\Collection
    {
        $courierRoleIds = DB::table('company_role_permissions as crp')
            ->join('company_roles as cr', 'cr.id', '=', 'crp.company_role_id')
            ->join('features as f', 'f.id', '=', 'crp.feature_id')
            ->where('cr.company_nit', $companyNit)
            ->where('f.slug', 'deliveries.self_assign')
            ->where('crp.can_read', true)
            ->pluck('cr.id');

        $memberships = CompanyUser::where('company_nit', $companyNit)
            ->whereIn('company_role_id', $courierRoleIds)
            ->with('role:id,is_system')
            ->get();

        // Sin sede activa (consolidado / sin scope): comportamiento previo.
        if ($activeBranchId === null) {
            return $memberships->pluck('user_id')->unique()->values();
        }

        // Roles is_system acceden a todas las sedes (bypass): entran sin
        // chequear branch_users. El resto requiere acceso explícito a la sede.
        $systemUserIds = $memberships->filter(fn (CompanyUser $m): bool => (bool) $m->role?->is_system)->pluck('user_id');
        $scopedUserIds = $memberships->reject(fn (CompanyUser $m): bool => (bool) $m->role?->is_system)->pluck('user_id');

        $branchScopedUserIds = $scopedUserIds->isEmpty()
            ? collect()
            : DB::table('branch_users')
                ->where('branch_id', $activeBranchId)
                ->whereIn('user_id', $scopedUserIds->all())
                ->pluck('user_id');

        return $systemUserIds->merge($branchScopedUserIds)->unique()->values();
    }

    private function assertCourierCapacity(User $deliverer, string $companyNit): void
    {
        $maxActive = (int) config('delivery.max_active_per_courier', 3);

        // El courier es un recurso de EMPRESA, no de sede: el cap de entregas
        // activas se cuenta cross-sede. Sin withoutBranchScope, BranchScope
        // limitaría el conteo a la sede activa y el límite real sería N×cap
        // (un courier con 3 activas en sede A podría tomar 3 más en sede B).
        $active = Delivery::withoutBranchScope()
            ->forCompany($companyNit)
            ->forUser($deliverer->id)
            ->where('status', 'pending')
            ->count();

        if ($active >= $maxActive) {
            throw new HttpResponseException(
                response()->json([
                    'message' => "El repartidor ha alcanzado el límite de {$maxActive} entregas activas.",
                    'max_active_per_courier' => $maxActive,
                    'active_deliveries' => $active,
                ], 422)
            );
        }
    }

    /** @return Collection<int, User> */
    public function getCouriers(Company $company, ?string $activeBranchId = null): Collection
    {
        $maxActive = config('delivery.max_active_per_courier', 3);
        $memberIds = $this->deliveryCandidateUserIds($company->nit, $activeBranchId);

        $couriers = User::whereIn('id', $memberIds)
            ->where('status', 'active')
            ->withCount([
                // Cross-sede: el courier es recurso de empresa. Sin escapar
                // BranchScope el conteo se limitaría a la sede activa y el badge
                // "disponible" no cuadraría con assertCourierCapacity.
                'deliveries as active_deliveries_count' => fn ($q) => $q
                    ->withoutGlobalScope(BranchScope::class)
                    ->where('company_nit', $company->nit)
                    ->where('status', 'pending'),
                'deliveries as daily_completed_count' => fn ($q) => $q
                    ->withoutGlobalScope(BranchScope::class)
                    ->where('company_nit', $company->nit)
                    ->where('status', 'completed')
                    ->whereDate('delivered_at', today()),
            ])
            ->orderBy('active_deliveries_count')
            ->get();

        $couriers->each(function (User $user) use ($maxActive): void {
            $user->setAttribute('available', $user->active_deliveries_count < $maxActive);
        });

        return $couriers;
    }

    /** @return Collection<int, User> */
    public function getAvailableDeliverers(Company $company, ?string $activeBranchId = null): Collection
    {
        $memberIds = $this->deliveryCandidateUserIds($company->nit, $activeBranchId);

        return User::whereIn('id', $memberIds)
            ->where('status', 'active')
            ->withCount([
                // Cross-sede: ver getCouriers — el courier es recurso de empresa.
                'deliveries as active_deliveries_count' => fn ($q) => $q
                    ->withoutGlobalScope(BranchScope::class)
                    ->where('company_nit', $company->nit)
                    ->where('status', 'pending'),
                'deliveries as daily_completed_count' => fn ($q) => $q
                    ->withoutGlobalScope(BranchScope::class)
                    ->where('company_nit', $company->nit)
                    ->where('status', 'completed')
                    ->whereDate('delivered_at', today()),
            ])
            ->orderBy('active_deliveries_count')
            ->get();
    }

    /**
     * @return array{
     *   total: int,
     *   completed: int,
     *   cancelled: int,
     *   avg_duration_minutes: float|null,
     *   pending: int,
     * }
     */
    public function getDelivererMetrics(User $deliverer, Company $company, Carbon $from, Carbon $to): array
    {
        $base = Delivery::forCompany($company->nit)
            ->forUser($deliverer->id)
            ->inPeriod($from, $to);

        $total = (clone $base)->count();
        $completed = (clone $base)->completed()->count();
        $cancelled = (clone $base)->where('status', 'cancelled')->count();
        $pending = (clone $base)->pending()->count();
        $avgDuration = (clone $base)->completed()->avg('duration_minutes');

        return [
            'total' => $total,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'pending' => $pending,
            'avg_duration_minutes' => $avgDuration ? round((float) $avgDuration, 1) : null,
        ];
    }

    /**
     * @return array<int, array{
     *   user_id: int,
     *   courier_name: string,
     *   total_deliveries: int,
     *   completed: int,
     *   cancelled: int,
     *   average_duration_minutes: float|null,
     *   success_rate: string
     * }>
     */
    public function getCompanyMetrics(string $companyNit, Carbon $from, Carbon $to): array
    {
        $deliveries = Delivery::forCompany($companyNit)
            ->inPeriod($from, $to)
            ->with('deliverer:id,name')
            ->get();

        return $deliveries
            ->groupBy('user_id')
            ->map(function (Collection $group, int|string $userId): array {
                $total = $group->count();
                $completed = $group->where('status', 'completed')->count();
                $cancelled = $group->where('status', 'cancelled')->count();
                $avgDuration = $group->where('status', 'completed')->avg('duration_minutes');
                $successRate = $total > 0 ? (int) round($completed / $total * 100) : 0;

                return [
                    'user_id' => (string) $userId,
                    'courier_name' => $group->first()?->deliverer?->name ?? 'Desconocido',
                    'total_deliveries' => $total,
                    'completed' => $completed,
                    'cancelled' => $cancelled,
                    'average_duration_minutes' => $avgDuration !== null ? round((float) $avgDuration, 1) : null,
                    'success_rate' => "{$successRate}%",
                ];
            })
            ->values()
            ->all();
    }
}
