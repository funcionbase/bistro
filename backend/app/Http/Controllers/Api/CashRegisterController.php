<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterExpense;
use App\Models\CashRegisterSession;
use App\Models\RestaurantMenu;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\BusinessHoursService;
use App\Services\CashRegisterService;
use App\Services\FeaturePermissionService;
use App\Services\ShiftActiveGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesiones de caja por empresa. Una sola sesión `open` a la vez; cualquier
 * usuario con permiso `orders.create` la abre/cierra y opera sobre la misma.
 *
 * - GET    /api/v1/cash-register/current   — sesión `open` actual o null.
 * - POST   /api/v1/cash-register/open      — abre nueva sesión.
 * - POST   /api/v1/cash-register/close     — cierra la sesión actual.
 * - GET    /api/v1/cash-register/sessions  — historial paginado.
 * - GET    /api/v1/cash-register/sessions/{id} — detalle.
 */
class CashRegisterController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly CashRegisterService $service,
        private readonly AuditService $auditService,
        private readonly BusinessHoursService $businessHours,
        private readonly ShiftActiveGuardService $shiftGuard,
    ) {}

    public function current(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'read');
        $companyNit = $this->activeCompanyNit($request);

        $session = $this->service->activeSession($companyNit);

        // Contexto operativo para el banner global del panel: si la caja está
        // cerrada Y el restaurante debería estar funcionando (horario hábil +
        // menú activo), avisar al usuario. Si no hay menú o estamos cerrados
        // por horario, no es necesario presionar al cajero.
        $menuActive = RestaurantMenu::forCompany($companyNit)->active()->exists();
        $hours = $this->businessHours->getCurrentStatus($companyNit, null, $request->attributes->get('active_branch_id'));
        $inBusinessHours = (bool) ($hours['menu_available'] ?? false);
        $shouldAlert = ! $session && $menuActive && $inBusinessHours;

        $payload = [
            'session' => null,
            'context' => [
                'menu_active' => $menuActive,
                'in_business_hours' => $inBusinessHours,
                'should_alert' => $shouldAlert,
            ],
        ];

        if ($session) {
            $session->load(['openedBy:id,name']);
            $live = $this->service->liveSummary($session);
            $payload['session'] = [
                'id' => $session->id,
                'status' => $session->status,
                'opened_at' => $session->opened_at?->toIso8601String(),
                'opening_amount' => (float) $session->opening_amount,
                'opened_by' => $session->openedBy ? [
                    'id' => $session->openedBy->id,
                    'name' => $session->openedBy->name,
                ] : null,
                'opening_notes' => $session->opening_notes,
                'live' => $live,
            ];
        }

        return response()->json(['data' => $payload]);
    }

    public function open(Request $request): JsonResponse
    {
        // Mismo permiso que crear órdenes — abrir caja es prerrequisito de cobrar.
        $this->permissionService->assertPermission($request, 'orders', 'create');

        $validated = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $user = $this->actingUser($request);
        if (! $user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        // Guard de turno activo: el usuario debe tener un employee_shift
        // scheduled, en la sede actual, cuya ventana contenga NOW(). Owners
        // y Administradores bypasean (responsabilidad supervisoria). Sin
        // turno activo → 403 con mensaje contable claro.
        $this->shiftGuard->assertActiveShift($user, $companyNit, $branchId);

        $session = $this->service->openSession(
            companyNit: $companyNit,
            branchId: $branchId,
            openedBy: $user,
            openingAmount: (float) $validated['opening_amount'],
            notes: $validated['notes'] ?? null,
        );

        $this->auditService->log('cash_register.opened', $user, $session, [
            'opening_amount' => (float) $session->opening_amount,
            'notes' => $session->opening_notes,
        ]);

        return response()->json(['data' => ['id' => $session->id, 'status' => $session->status]], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'update');

        $validated = $request->validate([
            'closing_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
            // Modo offline: el cliente reporta cuántas órdenes/cobros
            // tiene en su IndexedDB sin sincronizar. Si > 0, el service
            // bloquea el cierre (decisión: bloqueo duro sin escape).
            'pending_sync_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $user = $this->actingUser($request);
        if (! $user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        // Guard de turno activo también al cerrar. Si el turno acaba
        // de terminar entre apertura y cierre, esto puede bloquear el cierre
        // legítimo del colaborador; en ese caso un Owner o Administrador
        // debe cerrar la caja (ambos bypasean el guard).
        $this->shiftGuard->assertActiveShift($user, $companyNit, $branchId);

        $session = $this->service->closeSession(
            companyNit: $companyNit,
            closedBy: $user,
            closingAmount: (float) $validated['closing_amount'],
            notes: $validated['notes'] ?? null,
            pendingSyncCount: (int) ($validated['pending_sync_count'] ?? 0),
        );

        $this->auditService->log('cash_register.closed', $user, $session, [
            'opening_amount' => (float) $session->opening_amount,
            'closing_amount' => (float) $session->closing_amount,
            'expected_cash' => (float) $session->expected_cash,
            'cash_difference' => (float) $session->cash_difference,
        ]);

        return response()->json([
            'data' => [
                'id' => $session->id,
                'status' => $session->status,
                'opening_amount' => (float) $session->opening_amount,
                'closing_amount' => (float) $session->closing_amount,
                'expected_cash' => (float) $session->expected_cash,
                'cash_difference' => (float) $session->cash_difference,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');
        $companyNit = $this->activeCompanyNit($request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        $paginated = CashRegisterSession::forCompany($companyNit)
            ->with(['openedBy:id,name', 'closedBy:id,name'])
            ->orderByDesc('opened_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (CashRegisterSession $s) => $this->serializeSession($s))->all(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');
        $companyNit = $this->activeCompanyNit($request);

        $session = CashRegisterSession::forCompany($companyNit)
            ->with(['openedBy:id,name', 'closedBy:id,name'])
            ->findOrFail($id);

        $live = $session->isOpen() ? $this->service->liveSummary($session) : null;

        return response()->json([
            'data' => array_merge($this->serializeSession($session), ['live' => $live]),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeSession(CashRegisterSession $s): array
    {
        return [
            'id' => $s->id,
            'status' => $s->status,
            'opened_at' => $s->opened_at?->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'opening_amount' => (float) $s->opening_amount,
            'closing_amount' => $s->closing_amount !== null ? (float) $s->closing_amount : null,
            'expected_cash' => $s->expected_cash !== null ? (float) $s->expected_cash : null,
            'cash_difference' => $s->cash_difference !== null ? (float) $s->cash_difference : null,
            'opening_notes' => $s->opening_notes,
            'closing_notes' => $s->closing_notes,
            'opened_by' => $s->openedBy ? ['id' => $s->openedBy->id, 'name' => $s->openedBy->name] : null,
            'closed_by' => $s->closedBy ? ['id' => $s->closedBy->id, 'name' => $s->closedBy->name] : null,
        ];
    }

    /**
     * Registra un egreso (pago a domiciliario, propina distribuida, imprevisto…)
     * contra la sesión activa. Append-only — no hay PUT/DELETE asociado.
     */
    public function storeExpense(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'update');

        $categories = array_keys(config('cash_register.expense_categories', []));
        $methods = config('cash_register.expense_payment_methods', ['cash', 'card', 'transfer']);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', 'string', 'in:'.implode(',', $categories)],
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', $methods)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $user = $this->actingUser($request);
        if (! $user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $session = $this->service->requireActiveSession($companyNit);

        $expense = $this->service->recordExpense(
            session: $session,
            createdBy: $user,
            amount: (float) $validated['amount'],
            category: $validated['category'],
            description: $validated['description'] ?? null,
            paymentMethod: $validated['payment_method'] ?? 'cash',
        );

        $this->auditService->log('cash.expense.recorded', $user, $expense, [
            'amount' => (float) $expense->amount,
            'category' => $expense->category,
            'payment_method' => $expense->payment_method,
            'description' => $expense->description,
            'cash_session_id' => $expense->cash_session_id,
        ]);

        return response()->json([
            'data' => $this->serializeExpense($expense->loadMissing('createdBy:id,name')),
        ], 201);
    }

    /**
     * Lista los egresos de una sesión. Endpoint de auditoría — usa permiso de
     * lectura de reportes.
     */
    public function expensesIndex(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');
        $companyNit = $this->activeCompanyNit($request);

        $session = CashRegisterSession::forCompany($companyNit)->findOrFail($id);

        $expenses = $this->service->expensesForSession($session);

        $byCategory = [];
        foreach ($expenses as $exp) {
            $byCategory[$exp->category] = ($byCategory[$exp->category] ?? 0.0) + (float) $exp->amount;
        }

        return response()->json([
            'data' => $expenses->map(fn (CashRegisterExpense $e) => $this->serializeExpense($e))->all(),
            'summary' => [
                'total' => round((float) $expenses->sum(fn (CashRegisterExpense $e) => (float) $e->amount), 2),
                'count' => $expenses->count(),
                'by_category' => array_map(fn ($v) => round($v, 2), $byCategory),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeExpense(CashRegisterExpense $e): array
    {
        return [
            'id' => $e->id,
            'cash_session_id' => $e->cash_session_id,
            'amount' => (float) $e->amount,
            'category' => $e->category,
            'payment_method' => $e->payment_method,
            'description' => $e->description,
            'created_at' => $e->created_at?->toIso8601String(),
            'created_by' => $e->createdBy ? ['id' => $e->createdBy->id, 'name' => $e->createdBy->name] : null,
        ];
    }
}
