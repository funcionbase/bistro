<?php

namespace App\Http\Controllers\Api\Employees;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\User;
use App\Policies\EmployeeVinculationPolicy;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRUD de colaboradores. Owners y administradores con permisos
 * `employees.*` operan; los empleados sin permisos solo acceden a `/me`.
 *
 * Reglas contables (CLAUDE.md §REGLAS CONTABLES):
 *  - pay_rate y base_salary se persisten como decimal:2 (cast en Employee).
 *  - Toda mutación va en DB::transaction + AuditService::log con metadata
 *    accionable (campos cambiados, motivo si aplica).
 *  - Archivar es soft-delete (archived_at) — historial DIAN se preserva.
 */
class EmployeeController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly AuditService $auditService,
        private readonly EmployeeVinculationPolicy $vinculationPolicy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'read');
        $nit = $this->activeCompanyNit($request);

        $query = Employee::query()
            ->where('company_nit', $nit)
            ->with(['position:id,slug,label,color', 'primaryBranch:id,name,slug', 'user:id,name,email,status']);

        if ($request->boolean('include_archived') === false) {
            $query->whereNull('archived_at');
        }

        if ($status = $request->query('status')) {
            $query->where('vinculation_status', $status);
        }

        if ($branchId = $request->query('branch_id')) {
            $query->where('primary_branch_id', $branchId);
        }

        if ($positionId = $request->query('position_id')) {
            $query->where('position_id', $positionId);
        }

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $like = '%'.strtolower($search).'%';
                $q->whereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(doc_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            });
        }

        $employees = $query->orderBy('first_name')->paginate($request->integer('per_page', 25));

        $canViewSalary = $this->permissionService->hasPermission($request, 'employees', 'view_salary');

        return response()->json([
            'data' => $employees->getCollection()->map(fn (Employee $e) => $this->toArray($e, $canViewSalary))->values()->all(),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'create');
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);
        $data = $request->validated();

        $employee = DB::transaction(function () use ($nit, $data, $actor) {
            $linkedUserId = $this->resolveLinkedUserId($nit, $data['email']);

            $employee = Employee::create([
                ...$data,
                'company_nit' => $nit,
                'user_id' => $linkedUserId,
            ]);

            if (! empty($data['extra_branch_ids'])) {
                $employee->extraBranches()->sync($data['extra_branch_ids']);
            }

            $this->auditService->log(
                'employee.created',
                $actor,
                $employee,
                [
                    'doc_number' => $employee->doc_number,
                    'email' => $employee->email,
                    'pay_type' => $employee->pay_type,
                    'pay_rate' => (float) $employee->pay_rate,
                    'linked_user_id' => $linkedUserId,
                ]
            );

            return $employee->fresh(['position', 'primaryBranch', 'user']);
        });

        $canViewSalary = $this->permissionService->hasPermission($request, 'employees', 'view_salary');

        return response()->json(['data' => $this->toArray($employee, $canViewSalary)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'read');
        $employee = $this->findOrFail($request, $id);

        $canViewSalary = $this->permissionService->hasPermission($request, 'employees', 'view_salary');

        $employee->load([
            'position',
            'primaryBranch',
            'extraBranches',
            'user.companyUsers' => fn ($q) => $q->where('company_nit', $employee->company_nit)->with('role'),
        ]);

        return response()->json([
            'data' => $this->toArray($employee, $canViewSalary),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'update');
        $employee = $this->findOrFail($request, $id);
        $actor = $this->actingUserOrFail($request);
        $data = $request->validated();

        DB::transaction(function () use ($employee, $data, $actor) {
            $original = $employee->only(array_keys($data));
            $employee->fill($data);
            $changes = [];
            foreach ($data as $key => $value) {
                if (in_array($key, ['extra_branch_ids'], true)) {
                    continue;
                }
                if ($original[$key] ?? $value !== null) {
                    $changes[$key] = ['before' => $original[$key] ?? null, 'after' => $value];
                }
            }
            $employee->save();

            if (array_key_exists('extra_branch_ids', $data)) {
                $employee->extraBranches()->sync($data['extra_branch_ids']);
            }

            if (! empty($changes)) {
                $this->auditService->log('employee.updated', $actor, $employee, ['changes' => $changes]);
            }
        });

        $canViewSalary = $this->permissionService->hasPermission($request, 'employees', 'view_salary');

        return response()->json(['data' => $this->toArray($employee->fresh(['position', 'primaryBranch', 'user']), $canViewSalary)]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'delete');
        $employee = $this->findOrFail($request, $id);
        $actor = $this->actingUserOrFail($request);

        DB::transaction(function () use ($employee, $actor) {
            $employee->update(['archived_at' => now(), 'vinculation_status' => 'inactive']);

            EmployeeShift::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'scheduled')
                ->where('starts_at', '>=', now())
                ->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => 'vinculation_state',
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => $actor->id,
                ]);

            $this->auditService->log('employee.archived', $actor, $employee);
        });

        return response()->json(null, 204);
    }

    public function changeVinculationState(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'update');
        $employee = $this->findOrFail($request, $id);
        $actor = $this->actingUserOrFail($request);

        $data = $request->validate([
            'status' => ['required', Rule::in(config('employees.vinculation_statuses'))],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        if (in_array($data['status'], config('employees.absence_statuses'), true)) {
            if (empty($data['valid_from']) || empty($data['valid_until'])) {
                return response()->json([
                    'message' => 'Las ausencias requieren valid_from y valid_until.',
                ], 422);
            }
        }

        $denial = $this->vinculationPolicy->denialReason($actor, $employee, $data['status']);
        if ($denial !== null) {
            $this->auditService->log('employee.vinculation_change_denied', $actor, $employee, [
                'attempted_status' => $data['status'],
                'reason' => $denial,
            ]);

            $message = match ($denial) {
                EmployeeVinculationPolicy::REASON_SELF => 'No puedes desactivarte a ti mismo.',
                EmployeeVinculationPolicy::REASON_TARGET_IS_OWNER => 'Un propietario no puede ser desactivado.',
                EmployeeVinculationPolicy::REASON_ADMIN_CANNOT_DEMOTE_OWNER => 'Un administrador no puede desactivar a un propietario.',
                default => 'No tienes permiso para realizar esta acción.',
            };

            throw new AuthorizationException($message);
        }

        DB::transaction(function () use ($employee, $data, $actor) {
            $previous = $employee->vinculation_status;
            $employee->update([
                'vinculation_status' => $data['status'],
                'vinculation_valid_from' => $data['valid_from'] ?? null,
                'vinculation_valid_until' => $data['valid_until'] ?? null,
            ]);

            $cancelledCount = 0;
            if (in_array($data['status'], config('employees.absence_statuses'), true)) {
                $cancelledCount = $this->cascadeCancelShifts($employee->id, $data['valid_from'], $data['valid_until'], $actor->id);
            }

            // Sincronizar users.status si el colaborador está enlazado.
            if ($employee->user_id !== null) {
                $newUserStatus = $data['status'] === 'active' ? 'active' : 'inactive';
                User::where('id', $employee->user_id)->update(['status' => $newUserStatus]);
            }

            $this->auditService->log('employee.vinculation_changed', $actor, $employee, [
                'from' => $previous,
                'to' => $data['status'],
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'cascade_cancelled_shifts' => $cancelledCount,
            ]);

            if ($cancelledCount > 0) {
                $this->auditService->log('shift.bulk_cancelled_by_state', $actor, $employee, [
                    'count' => $cancelledCount,
                    'window' => ['from' => $data['valid_from'], 'to' => $data['valid_until']],
                ]);
            }
        });

        return response()->json(['data' => ['status' => $employee->vinculation_status]]);
    }

    public function viewSalary(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'employees', 'view_salary');
        $employee = $this->findOrFail($request, $id);
        $actor = $this->actingUserOrFail($request);

        $this->auditService->log('employee.salary_viewed', $actor, $employee);

        return response()->json([
            'data' => [
                'employee_id' => $employee->id,
                'pay_type' => $employee->pay_type,
                'pay_rate' => (float) $employee->pay_rate,
                'base_salary' => $employee->base_salary !== null ? (float) $employee->base_salary : null,
            ],
        ]);
    }

    private function findOrFail(Request $request, string $id): Employee
    {
        $nit = $this->activeCompanyNit($request);

        return Employee::query()
            ->where('company_nit', $nit)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Cuando el email del colaborador coincide con un user existente, enlazar
     * automáticamente — siempre que ese user sea miembro de la empresa.
     */
    private function resolveLinkedUserId(string $companyNit, string $email): ?int
    {
        $user = User::query()
            ->where('email', $email)
            ->whereHas('companyUsers', fn ($q) => $q->where('company_nit', $companyNit))
            ->first();

        return $user?->id;
    }

    private function cascadeCancelShifts(string $employeeId, string $validFrom, string $validUntil, string $actorId): int
    {
        $tz = config('app.timezone', 'America/Bogota');
        $startDay = Carbon::parse($validFrom, $tz)->startOfDay();
        $endDay = Carbon::parse($validUntil, $tz)->endOfDay();

        return EmployeeShift::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'scheduled')
            ->where('starts_at', '<=', $endDay)
            ->where('ends_at', '>=', $startDay)
            ->update([
                'status' => 'cancelled',
                'cancellation_reason' => 'vinculation_state',
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actorId,
            ]);
    }

    /** @return array<string, mixed> */
    private function toArray(Employee $e, bool $canViewSalary): array
    {
        $companyUser = $e->relationLoaded('user') && $e->user
            ? $e->user->companyUsers->firstWhere('company_nit', $e->company_nit)
            : null;

        return [
            'id' => $e->id,
            'company_nit' => $e->company_nit,
            'user_id' => $e->user_id,
            'user' => $e->relationLoaded('user') && $e->user ? [
                'id' => $e->user->id,
                'name' => $e->user->name,
                'email' => $e->user->email,
                'status' => $e->user->status,
                'role' => $companyUser?->role ? [
                    'id' => $companyUser->role->id,
                    'name' => $companyUser->role->name,
                    'slug' => $companyUser->role->slug,
                    'is_system' => (bool) $companyUser->role->is_system,
                ] : null,
                'linked_at' => $companyUser?->created_at?->toIso8601String(),
            ] : null,
            'primary_branch' => $e->primaryBranch ? [
                'id' => $e->primaryBranch->id,
                'name' => $e->primaryBranch->name,
                'slug' => $e->primaryBranch->slug,
            ] : null,
            'position' => $e->position ? [
                'id' => $e->position->id,
                'slug' => $e->position->slug,
                'label' => $e->position->label,
                'color' => $e->position->color,
            ] : null,
            'doc_type' => $e->doc_type,
            'doc_number' => $e->doc_number,
            'first_name' => $e->first_name,
            'last_name' => $e->last_name,
            'full_name' => $e->fullName(),
            'email' => $e->email,
            'phone' => $e->phone,
            'birth_date' => $e->birth_date?->toDateString(),
            'blood_type' => $e->blood_type,
            'address' => $e->address,
            'city' => $e->city,
            'eps' => $e->eps,
            'arl' => $e->arl,
            'pension_fund' => $e->pension_fund,
            'severance_fund' => $e->severance_fund,
            'bank' => $e->bank,
            'account_type' => $e->account_type,
            'account_number' => $e->account_number,
            'emergency_contact_name' => $e->emergency_contact_name,
            'emergency_contact_phone' => $e->emergency_contact_phone,
            'uniform_size' => $e->uniform_size,
            'contract_type' => $e->contract_type,
            'pay_type' => $e->pay_type,
            // El frontend siempre recibe el valor enmascarado salvo que el
            // actor tenga `employees.view_salary`. La revelación bajo demanda
            // pasa por viewSalary() y queda auditada.
            'pay_rate' => $canViewSalary ? (float) $e->pay_rate : null,
            'pay_rate_masked' => ! $canViewSalary,
            'base_salary' => $canViewSalary ? ($e->base_salary !== null ? (float) $e->base_salary : null) : null,
            'hire_date' => $e->hire_date?->toDateString(),
            'vinculation_status' => $e->vinculation_status,
            'vinculation_valid_from' => $e->vinculation_valid_from?->toDateString(),
            'vinculation_valid_until' => $e->vinculation_valid_until?->toDateString(),
            'min_days_off_override' => $e->min_days_off_override,
            'archived_at' => $e->archived_at?->toIso8601String(),
        ];
    }
}
