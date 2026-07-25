<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegister\StoreCashIncomeRequest;
use App\Http\Requests\CashRegister\StoreCashRegisterRequest;
use App\Http\Requests\CashRegister\UpdateCashRegisterRequest;
use App\Models\CashRegister;
use App\Models\CashRegisterExpense;
use App\Models\CashRegisterIncome;
use App\Models\CashRegisterSession;
use App\Models\RestaurantMenu;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\BusinessHoursService;
use App\Services\CashRegisterService;
use App\Services\FeaturePermissionService;
use App\Services\ShiftActiveGuardService;
use Carbon\Carbon;
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
        $branchId = $this->activeBranchId($request);

        // Multi-caja (#117): si el cliente indica `cash_session_id`, devolvemos
        // el estado de ESA caja; si no, fallback a la única caja abierta de la
        // sede (sede mono-caja / cliente legacy). El catálogo completo de cajas
        // vive en GET cash-register/registers.
        $requestedSessionId = (string) $request->query('cash_session_id', '');
        $session = $requestedSessionId !== ''
            ? CashRegisterSession::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
                ->where('id', $requestedSessionId)
                ->where('status', 'open')
                ->first()
            : $this->service->activeSessionForBranch($companyNit, $branchId);

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
            $session->load(['openedBy:id,name', 'cashRegister:id,name']);
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
                'cash_register_id' => $session->cash_register_id,
                'cash_register_name' => $session->cashRegister?->name,
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
            // Multi-caja (#117): qué caja abre. Opcional para retrocompat
            // (mono-caja → "Caja principal" por defecto en el service).
            'cash_register_id' => ['nullable', 'uuid'],
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
            cashRegisterId: $validated['cash_register_id'] ?? null,
        );

        $this->auditService->log('cash_register.opened', $user, $session, [
            'opening_amount' => (float) $session->opening_amount,
            'cash_register_id' => $session->cash_register_id,
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
            // Multi-caja (#117): qué caja se cierra. Opcional para retrocompat
            // (si hay una sola abierta en la sede, esa).
            'cash_session_id' => ['nullable', 'uuid'],
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

        // Resolver sesión antes del cierre para verificar autoría (#117 Fase 3).
        // El lockForUpdate real ocurre dentro de closeSession; este resolve previo
        // es solo para el chequeo de permiso — TOCTOU aceptable en este flujo.
        $pendingSession = $this->service->resolveSessionForCharge(
            $companyNit,
            $branchId,
            $validated['cash_session_id'] ?? null,
        );

        // Cerrar la caja de otro cajero requiere `cash_register.operate_others`.
        // Cubre el caso "turno anterior no cerró, supervisor cierra" (#117 Fase 3).
        // is_system=true (owner/admin/employee) bypasea automáticamente vía hasPermission.
        $isOthersCash = $pendingSession->opened_by_user_id !== $user->id;
        if ($isOthersCash && ! $this->permissionService->hasPermission($request, 'cash_register', 'operate_others')) {
            return response()->json([
                'message' => 'No tenés permiso para cerrar la caja de otro cajero. Pedí el permiso "Operar caja de otro cajero".',
            ], 403);
        }

        $session = $this->service->closeSession(
            companyNit: $companyNit,
            branchId: $branchId,
            closedBy: $user,
            closingAmount: (float) $validated['closing_amount'],
            notes: $validated['notes'] ?? null,
            pendingSyncCount: (int) ($validated['pending_sync_count'] ?? 0),
            cashSessionId: $pendingSession->id,
        );

        $auditAction = $isOthersCash ? 'cash_register.taken_over' : 'cash_register.closed';
        $auditMeta = [
            'cash_session_id' => $session->id,
            'cash_register_id' => $session->cash_register_id,
            'opening_amount' => (float) $session->opening_amount,
            'closing_amount' => (float) $session->closing_amount,
            'expected_cash' => (float) $session->expected_cash,
            'cash_difference' => (float) $session->cash_difference,
        ];
        if ($isOthersCash) {
            $auditMeta['original_opened_by_user_id'] = $pendingSession->opened_by_user_id;
        }

        $this->auditService->log($auditAction, $user, $session, $auditMeta);

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

        $validated = $request->validate([
            'date_from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $perPage = min((int) $request->input('per_page', 25), 100);
        // `detailed=1`: anexa el arqueo por turno (ventas por método, egresos e
        // ingresos) para el informe de cierre de caja. El historial simple del
        // panel de reportes no lo pide y evita el costo del liveSummary.
        $detailed = $request->boolean('detailed');

        $query = CashRegisterSession::forCompany($companyNit)
            ->with(['openedBy:id,name', 'closedBy:id,name', 'cashRegister:id,name'])
            ->orderByDesc('opened_at');

        // Filtro por día de APERTURA en TZ Bogota (un turno que cruza medianoche
        // se agrupa por su día de apertura). Mismo rango que CashDrawerController.
        if (! empty($validated['date_from']) || ! empty($validated['date_to'])) {
            $tz = config('orders.timezone', 'America/Bogota');
            $today = Carbon::now($tz)->toDateString();
            $from = Carbon::parse($validated['date_from'] ?? $today, $tz)->startOfDay();
            $to = Carbon::parse($validated['date_to'] ?? $validated['date_from'] ?? $today, $tz)->endOfDay();
            // opened_at se guarda en wall-clock del APP_TIMEZONE (no UTC).
            $query->whereBetween('opened_at', [
                $from->copy()->setTimezone(config('app.timezone')),
                $to->copy()->setTimezone(config('app.timezone')),
            ]);
        }

        $paginated = $query->paginate($perPage);

        $map = $detailed
            ? fn (CashRegisterSession $s) => $this->serializeSessionDetailed($s)
            : fn (CashRegisterSession $s) => $this->serializeSession($s);

        return response()->json([
            'data' => $paginated->getCollection()->map($map)->all(),
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
            ->with(['openedBy:id,name', 'closedBy:id,name', 'cashRegister:id,name'])
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
            'cash_register_id' => $s->cash_register_id,
            'cash_register_name' => $s->relationLoaded('cashRegister') ? $s->cashRegister?->name : null,
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
     * Sesión + arqueo del turno (ventas por método, egresos, ingresos) para el
     * informe de cierre de caja. Sirve para turnos abiertos y cerrados: los
     * SUMs se calculan por `cash_session_id`, no dependen del estado.
     *
     * @return array<string, mixed>
     */
    private function serializeSessionDetailed(CashRegisterSession $s): array
    {
        // ponytail: liveSummary hace varias queries por sesión; un rango de
        // informe trae pocas sesiones (1-3/día). Si un rango grande se pone
        // lento, batchear los GROUP BY por cash_session_id en una sola query.
        $live = $this->service->liveSummary($s);

        return array_merge($this->serializeSession($s), [
            'breakdown' => [
                'by_method' => $live['by_method'],
                'expenses' => $live['expenses'],
                'incomes' => $live['incomes'],
                'couriers' => $live['couriers'],
            ],
        ]);
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
            'description' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
            // Multi-caja (#117): contra qué caja se carga el egreso.
            'cash_session_id' => ['nullable', 'uuid'],
            // F6: pago de tarifas a domiciliario — vincula el egreso al
            // repartidor para el cruce por courier del cierre.
            'courier_user_id' => ['nullable', 'uuid'],
        ]);

        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $user = $this->actingUser($request);
        if (! $user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $session = $this->service->resolveSessionForCharge(
            $companyNit,
            $branchId,
            $validated['cash_session_id'] ?? null,
        );

        // El bloqueo de egresos en efectivo que dejarían la caja negativa se
        // valida DENTRO de recordExpense (bajo lock de la sesión) para que dos
        // egresos concurrentes no pasen ambos el chequeo (TOCTOU).
        $expense = $this->service->recordExpense(
            session: $session,
            createdBy: $user,
            amount: (float) $validated['amount'],
            category: $validated['category'],
            description: $validated['description'] ?? null,
            paymentMethod: $validated['payment_method'] ?? 'cash',
            enforceNonNegativeCash: true,
            courierUserId: $validated['courier_user_id'] ?? null,
        );

        $this->auditService->log('cash.expense.recorded', $user, $expense, [
            'amount' => (float) $expense->amount,
            'category' => $expense->category,
            'payment_method' => $expense->payment_method,
            'description' => $expense->description,
            'cash_session_id' => $expense->cash_session_id,
            'courier_user_id' => $expense->courier_user_id,
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
            // F6: repartidor vinculado al pago de tarifas (cruce del cierre).
            'courier_user_id' => $e->courier_user_id,
            'created_at' => $e->created_at?->toIso8601String(),
            'created_by' => $e->createdBy ? ['id' => $e->createdBy->id, 'name' => $e->createdBy->name] : null,
        ];
    }

    /**
     * Registra una entrada de efectivo (aporte de socio, préstamo, ajuste…)
     * contra la sesión activa. Append-only — no hay PUT/DELETE asociado.
     * A diferencia del egreso, no valida "supera el efectivo": una entrada solo
     * SUMA al cajón, nunca lo deja negativo.
     */
    public function storeIncome(StoreCashIncomeRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'orders', 'update');

        $validated = $request->validated();

        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $user = $this->actingUser($request);
        if (! $user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $session = $this->service->resolveSessionForCharge(
            $companyNit,
            $branchId,
            $validated['cash_session_id'] ?? null,
        );

        $income = $this->service->recordIncome(
            session: $session,
            createdBy: $user,
            amount: (float) $validated['amount'],
            category: $validated['category'],
            description: $validated['description'] ?? null,
            paymentMethod: $validated['payment_method'] ?? 'cash',
        );

        $this->auditService->log('cash.income.recorded', $user, $income, [
            'amount' => (float) $income->amount,
            'category' => $income->category,
            'payment_method' => $income->payment_method,
            'description' => $income->description,
            'cash_session_id' => $income->cash_session_id,
        ]);

        return response()->json([
            'data' => $this->serializeIncome($income->loadMissing('createdBy:id,name')),
        ], 201);
    }

    /**
     * Lista las entradas de una sesión. Endpoint de auditoría — usa permiso de
     * lectura de reportes.
     */
    public function incomesIndex(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');
        $companyNit = $this->activeCompanyNit($request);

        $session = CashRegisterSession::forCompany($companyNit)->findOrFail($id);

        $incomes = $this->service->incomesForSession($session);

        $byCategory = [];
        foreach ($incomes as $inc) {
            $byCategory[$inc->category] = ($byCategory[$inc->category] ?? 0.0) + (float) $inc->amount;
        }

        return response()->json([
            'data' => $incomes->map(fn (CashRegisterIncome $i) => $this->serializeIncome($i))->all(),
            'summary' => [
                'total' => round((float) $incomes->sum(fn (CashRegisterIncome $i) => (float) $i->amount), 2),
                'count' => $incomes->count(),
                'by_category' => array_map(fn ($v) => round($v, 2), $byCategory),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeIncome(CashRegisterIncome $i): array
    {
        return [
            'id' => $i->id,
            'cash_session_id' => $i->cash_session_id,
            'amount' => (float) $i->amount,
            'category' => $i->category,
            'payment_method' => $i->payment_method,
            'description' => $i->description,
            'created_at' => $i->created_at?->toIso8601String(),
            'created_by' => $i->createdBy ? ['id' => $i->createdBy->id, 'name' => $i->createdBy->name] : null,
        ];
    }

    /**
     * Catálogo de cajas de la sede activa con su sesión abierta (si la hay).
     * Lo consume el selector "¿qué caja operás?" y el panel supervisor.
     */
    public function registers(Request $request): JsonResponse
    {
        // Ver el estado de cajas es prerrequisito de operar; mismo permiso de
        // lectura de órdenes (cualquier cajero lo necesita para elegir caja).
        $this->permissionService->assertPermission($request, 'orders', 'read');
        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);

        $includeArchived = $request->boolean('all');
        $registers = $this->service->registersForBranch($companyNit, $branchId, $includeArchived);

        return response()->json([
            'data' => $registers->map(fn (CashRegister $r) => $this->serializeRegister($r))->all(),
            'can_manage' => $this->permissionService->hasPermission($request, 'cash_register', 'manage'),
        ]);
    }

    /**
     * Crea una caja en la sede activa. Permiso `cash_register.manage` validado
     * en la ruta (sensible de sede — admin no auto, ver Fase 3 RBAC).
     */
    public function storeRegister(StoreCashRegisterRequest $request): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $user = $this->actingUser($request);
        if (! $user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $register = CashRegister::create([
            'company_nit' => $companyNit,
            'branch_id' => $branchId,
            'name' => $request->validated('name'),
            'is_active' => true,
            'sort_order' => (int) ($request->validated('sort_order') ?? 0),
        ]);

        $this->auditService->log('cash_register.created', $user, $register, [
            'name' => $register->name,
        ]);

        return response()->json(['data' => $this->serializeRegister($register)], 201);
    }

    /**
     * Renombra / (des)activa / archiva una caja. Archivar es el "borrado"
     * contable: no se elimina físicamente para preservar FKs de sesiones y
     * receipts históricos. No se puede archivar una caja con sesión abierta.
     */
    public function updateRegister(UpdateCashRegisterRequest $request, string $id): JsonResponse
    {
        $companyNit = $this->activeCompanyNit($request);
        $branchId = $this->activeBranchId($request);
        $user = $this->actingUser($request);
        if (! $user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        /** @var CashRegister $register */
        $register = CashRegister::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->findOrFail($id);

        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $register->name = $data['name'];
        }
        if (array_key_exists('is_active', $data)) {
            $register->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('sort_order', $data)) {
            $register->sort_order = (int) $data['sort_order'];
        }

        if (! empty($data['archived']) && ! $register->isArchived()) {
            $hasOpen = CashRegisterSession::query()
                ->where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->exists();
            if ($hasOpen) {
                return response()->json([
                    'message' => 'No se puede archivar una caja con una sesión abierta. Ciérrala primero.',
                ], 422);
            }
            $register->archived_at = now();
            $register->is_active = false;
        } elseif (isset($data['archived']) && $data['archived'] === false) {
            $register->archived_at = null;
        }

        $register->save();

        $this->auditService->log('cash_register.updated', $user, $register, [
            'name' => $register->name,
            'is_active' => $register->is_active,
            'archived' => $register->isArchived(),
        ]);

        return response()->json(['data' => $this->serializeRegister($register->fresh())]);
    }

    /** @return array<string, mixed> */
    private function serializeRegister(CashRegister $r): array
    {
        $open = $r->relationLoaded('sessions') ? $r->sessions->firstWhere('status', 'open') : null;

        return [
            'id' => $r->id,
            'name' => $r->name,
            'is_active' => (bool) $r->is_active,
            'sort_order' => (int) $r->sort_order,
            'archived' => $r->isArchived(),
            'open_session' => $open ? [
                'id' => $open->id,
                'opened_at' => $open->opened_at?->toIso8601String(),
                'opening_amount' => (float) $open->opening_amount,
                'opened_by' => $open->openedBy ? ['id' => $open->openedBy->id, 'name' => $open->openedBy->name] : null,
            ] : null,
        ];
    }
}
