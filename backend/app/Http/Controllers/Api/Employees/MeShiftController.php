<?php

namespace App\Http\Controllers\Api\Employees;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Vista del colaborador sobre sí mismo. No requiere los features de
 * employees.* — el usuario accede a SUS datos. Si no tiene perfil `employees`
 * vinculado, devuelve 404.
 *
 * `viewMyProfile` enmascara `pay_rate`/`base_salary` con el flag
 * `salary_masked: true`; `viewMySalary` lo destapa y audita
 * `employee.salary_viewed_self`.
 */
class MeShiftController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(private readonly AuditService $auditService) {}

    public function shifts(Request $request): JsonResponse
    {
        $employee = $this->myEmployeeOr404($request);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($data['from'] ?? now()->startOfWeek()->toDateString());
        $to = Carbon::parse($data['to'] ?? now()->endOfWeek()->toDateString());

        $shifts = EmployeeShift::query()
            ->where('employee_id', $employee->id)
            ->where('starts_at', '<', $to->endOfDay())
            ->where('ends_at', '>', $from->startOfDay())
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'data' => $shifts->map(fn (EmployeeShift $s) => [
                'id' => $s->id,
                'branch_id' => $s->branch_id,
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
                'status' => $s->status,
                'cancellation_reason' => $s->cancellation_reason,
            ])->values()->all(),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $employee = $this->myEmployeeOr404($request);
        $employee->load(['position', 'primaryBranch']);

        return response()->json([
            'data' => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'position' => $employee->position ? [
                    'label' => $employee->position->label,
                    'color' => $employee->position->color,
                ] : null,
                'primary_branch' => $employee->primaryBranch ? [
                    'id' => $employee->primaryBranch->id,
                    'name' => $employee->primaryBranch->name,
                ] : null,
                'contract_type' => $employee->contract_type,
                'pay_type' => $employee->pay_type,
                'pay_rate_masked' => true,
                'vinculation_status' => $employee->vinculation_status,
                'hire_date' => $employee->hire_date?->toDateString(),
            ],
        ]);
    }

    public function viewSalary(Request $request): JsonResponse
    {
        $employee = $this->myEmployeeOr404($request);
        $actor = $this->actingUserOrFail($request);

        $this->auditService->log('employee.salary_viewed_self', $actor, $employee);

        return response()->json([
            'data' => [
                'pay_type' => $employee->pay_type,
                'pay_rate' => (float) $employee->pay_rate,
                'base_salary' => $employee->base_salary !== null ? (float) $employee->base_salary : null,
            ],
        ]);
    }

    private function myEmployeeOr404(Request $request): Employee
    {
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);

        $employee = Employee::query()
            ->where('company_nit', $nit)
            ->where('user_id', $actor->id)
            ->whereNull('archived_at')
            ->first();

        if ($employee === null) {
            abort(404, 'No tienes un perfil de colaborador en esta empresa.');
        }

        return $employee;
    }
}
