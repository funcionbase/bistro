<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de mesas físicas — admin de configuración.
 *
 * Requiere auth + permission company.update. Soft-archive (no hard-delete)
 * para preservar histórico contable (audit_logs y receipts apuntan a
 * table_session_id, no a table_id directamente, pero igual conservamos
 * la mesa).
 *
 * Idempotencia de qr_token: si una mesa pierde su QR físico, el owner
 * puede regenerarlo con POST /tables/{id}/regenerate-qr. La acción queda
 * auditada y el QR viejo deja de funcionar inmediatamente.
 */
class TableAdminController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        $tables = Table::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->orderByRaw('number::int ASC')
            ->get(['id', 'number', 'capacity', 'qr_token', 'status', 'archived_at']);

        // Sesiones activas por mesa en la sede actual. Una sola query agrupada
        // por table_id para evitar N+1; luego se compone el active_session por
        // cada Table en memoria. La sesión "activa" usa `tables.active_statuses`
        // (open / locked / awaiting_payment según config), nunca terminales.
        $activeSessions = TableSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereIn('table_id', $tables->pluck('id')->all())
            ->whereIn('status', config('tables.active_statuses'))
            ->with(['order:id,table_session_id,status', 'guests:id,table_session_id'])
            ->get()
            ->keyBy('table_id');

        $consumableItemsCount = [];
        if ($activeSessions->isNotEmpty()) {
            $orderIds = $activeSessions
                ->map(fn (TableSession $s) => optional($s->order)->id)
                ->filter()
                ->values()
                ->all();
            if (! empty($orderIds)) {
                $consumableItemsCount = OrderItem::query()
                    ->whereIn('order_id', $orderIds)
                    ->whereIn('status', config('orders.item_statuses.consumable'))
                    ->selectRaw('order_id, COUNT(*) as c')
                    ->groupBy('order_id')
                    ->pluck('c', 'order_id')
                    ->all();
            }
        }

        return response()->json([
            'data' => $tables->map(function (Table $t) use ($activeSessions, $consumableItemsCount) {
                $session = $activeSessions->get($t->id);
                $order = $session ? $session->order : null;
                $orderId = optional($order)->id;
                $orderStatus = optional($order)->status;

                return [
                    'id' => $t->id,
                    'number' => $t->number,
                    'capacity' => (int) $t->capacity,
                    'qr_token' => $t->qr_token,
                    'status' => $t->status,
                    'archived_at' => optional($t->archived_at)?->toIso8601String(),
                    'active_session' => $session ? [
                        'id' => $session->id,
                        'status' => $session->status,
                        'guests_count' => $session->guests->count(),
                        'order_id' => $orderId,
                        'order_status' => $orderStatus,
                        // `items_consumable_count > 0` significa que hay pedidos
                        // en producción/entrega (approved/in_kitchen/ready/served)
                        // sin pago. El frontend usa esto para bloquear "Liberar".
                        'items_consumable_count' => $orderId ? (int) ($consumableItemsCount[$orderId] ?? 0) : 0,
                    ] : null,
                ];
            })->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'capacity' => ['nullable', 'integer', 'between:1,30'],
        ]);

        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');
        $user = $this->actor($request);

        $table = DB::transaction(function () use ($payload, $branchId, $companyNit, $user, $request) {
            // Siguiente número monotónico por sede considerando TODAS las
            // mesas (activas + desactivadas). Una mesa desactivada conserva
            // su número original — la nueva nunca recibe ese mismo número
            // para evitar confusión con el QR físico ya impreso.
            $next = (int) Table::query()
                ->where('branch_id', $branchId)
                ->selectRaw('COALESCE(MAX(number::int), 0) + 1 AS next')
                ->value('next');

            $t = new Table;
            $t->company_nit = $companyNit;
            $t->branch_id = $branchId;
            $t->number = (string) $next;
            $t->capacity = (int) ($payload['capacity'] ?? 4);
            $t->save();

            $this->audit->log(
                'table.created',
                user: $user,
                auditable: $t,
                data: ['number' => $t->number, 'capacity' => $t->capacity],
                request: $request,
            );

            return $t;
        });

        return response()->json(['data' => $this->serialize($table)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        // El número de mesa NO es editable — se asigna secuencialmente al
        // crear y se renumera al borrar. Aquí solo se ajusta la capacidad.
        $payload = $request->validate([
            'capacity' => ['nullable', 'integer', 'between:1,30'],
        ]);

        $table = $this->loadTable($request, $id);
        $user = $this->actor($request);

        $changes = [];
        if (isset($payload['capacity']) && (int) $payload['capacity'] !== (int) $table->capacity) {
            $changes['capacity'] = ['from' => $table->capacity, 'to' => (int) $payload['capacity']];
            $table->capacity = (int) $payload['capacity'];
        }

        if ($changes !== []) {
            DB::transaction(function () use ($table, $changes, $user, $request) {
                $table->save();
                $this->audit->log(
                    'table.updated',
                    user: $user,
                    auditable: $table,
                    data: $changes,
                    request: $request,
                );
            });
        }

        return response()->json(['data' => $this->serialize($table)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $table = $this->loadTable($request, $id);
        $user = $this->actor($request);

        // No permitir desactivar si tiene sesión activa.
        $hasActive = TableSession::withoutBranchScope()
            ->where('table_id', $table->id)
            ->whereIn('status', config('tables.active_statuses'))
            ->exists();
        if ($hasActive) {
            return response()->json([
                'message' => 'No se puede desactivar una mesa con sesión activa. Cerrá la sesión primero.',
            ], 422);
        }

        DB::transaction(function () use ($table, $user, $request) {
            // Mantiene el número original (no se renumera). La mesa nueva
            // que se cree después tomará MAX+1, así nunca hay dos mesas
            // (activa y archivada) con el mismo número.
            $table->archived_at = now();
            $table->status = 'blocked';
            $table->save();

            $this->audit->log(
                'table.deactivated',
                user: $user,
                auditable: $table,
                data: ['number' => $table->number],
                request: $request,
            );
        });

        return response()->json(['data' => $this->serialize($table->refresh())]);
    }

    /**
     * Reactiva una mesa previamente desactivada. Conserva su `number` original
     * — como nunca reusamos números, no hay riesgo de colisión con otra mesa
     * activa con el mismo número.
     */
    public function restore(Request $request, string $id): JsonResponse
    {
        $table = $this->loadTable($request, $id);
        $user = $this->actor($request);

        if ($table->archived_at === null) {
            return response()->json(['message' => 'La mesa ya está activa.'], 422);
        }

        DB::transaction(function () use ($table, $user, $request) {
            $table->archived_at = null;
            $table->status = 'available';
            $table->save();

            $this->audit->log(
                'table.reactivated',
                user: $user,
                auditable: $table,
                data: ['number' => $table->number],
                request: $request,
            );
        });

        return response()->json(['data' => $this->serialize($table->refresh())]);
    }

    public function regenerateQr(Request $request, string $id): JsonResponse
    {
        $table = $this->loadTable($request, $id);
        $user = $this->actor($request);

        DB::transaction(function () use ($table, $user, $request) {
            $oldToken = $table->qr_token;
            $table->qr_token = Table::generateQrToken();
            $table->save();

            $this->audit->log(
                'table.qr_regenerated',
                user: $user,
                auditable: $table,
                data: ['old_token_prefix' => substr($oldToken, 0, 6).'…'],
                request: $request,
            );
        });

        return response()->json(['data' => $this->serialize($table)]);
    }

    private function loadTable(Request $request, string $id): Table
    {
        $branchId = $request->attributes->get('active_branch_id');
        $companyNit = $request->attributes->get('active_company_nit');

        return Table::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        $sub = $request->attributes->get('jwt_payload')['sub'] ?? null;

        return User::query()->findOrFail($sub);
    }

    /** @return array<string, mixed> */
    private function serialize(Table $table): array
    {
        return [
            'id' => $table->id,
            'number' => $table->number,
            'capacity' => (int) $table->capacity,
            'qr_token' => $table->qr_token,
            'status' => $table->status,
            'archived_at' => optional($table->archived_at)?->toIso8601String(),
        ];
    }
}
