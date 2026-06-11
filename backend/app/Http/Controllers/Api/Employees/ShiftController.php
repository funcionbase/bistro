<?php

namespace App\Http\Controllers\Api\Employees;

use App\Http\Controllers\Concerns\ResolvesActiveContext;
use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use App\Services\Shifts\ShiftSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Planificador de turnos. Permisos:
 *  - shifts.read para listar.
 *  - shifts.manage para crear/editar/cancelar.
 *  - shifts.suggest para generar borradores automáticos.
 *
 * Toda mutación auditada. Lock pesimista sobre el empleado al crear/cancelar
 * para evitar carreras (e.g. dos admins asignando el mismo hueco).
 */
class ShiftController extends Controller
{
    use ResolvesActiveContext, ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly AuditService $auditService,
        private readonly ShiftSuggestionService $suggestionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'shifts', 'read');
        $nit = $this->activeCompanyNit($request);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'uuid'],
            'employee_id' => ['nullable', 'uuid'],
        ]);

        $shifts = EmployeeShift::query()
            ->whereHas('employee', fn ($q) => $q->where('company_nit', $nit))
            ->where('starts_at', '<', Carbon::parse($data['to'])->endOfDay())
            ->where('ends_at', '>', Carbon::parse($data['from'])->startOfDay())
            ->when($data['branch_id'] ?? null, fn ($q, $bid) => $q->where('branch_id', $bid))
            ->when($data['employee_id'] ?? null, fn ($q, $eid) => $q->where('employee_id', $eid))
            ->with(['employee:id,first_name,last_name,position_id,primary_branch_id', 'employee.position:id,slug,label,color'])
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'data' => $shifts->map(fn (EmployeeShift $s) => $this->toArray($s))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'shifts', 'create');
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);

        $data = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $shift = DB::transaction(function () use ($data, $actor, $nit) {
            $employee = Employee::query()
                ->where('id', $data['employee_id'])
                ->where('company_nit', $nit)
                ->lockForUpdate()
                ->firstOrFail();

            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = Carbon::parse($data['ends_at']);

            $overlap = EmployeeShift::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'scheduled')
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($overlap) {
                abort(422, 'El colaborador ya tiene un turno en esa franja.');
            }

            $shift = EmployeeShift::create([
                'employee_id' => $employee->id,
                'branch_id' => $data['branch_id'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'scheduled',
                'created_by_user_id' => $actor->id,
            ]);

            $this->auditService->log('shift.created', $actor, $shift, [
                'employee_id' => $employee->id,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
            ]);

            return $shift->fresh(['employee.position']);
        });

        return response()->json(['data' => $this->toArray($shift)], 201);
    }

    /**
     * Asigna el mismo colaborador a varios turnos (multi-día) en una sola operación.
     *
     * Best-effort: los turnos que se solapan con uno ya programado se saltan y se
     * reportan en `skipped`, sin abortar el resto. El lock pesimista se toma una
     * sola vez sobre el empleado; como los INSERT son visibles dentro de la misma
     * transacción, la detección de solape también atrapa choques intra-lote
     * (dos fechas iguales o cruzadas en el mismo envío).
     */
    public function storeBulk(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'shifts', 'create');
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);

        $data = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'shifts' => ['required', 'array', 'min:1', 'max:60'],
            'shifts.*.starts_at' => ['required', 'date'],
            'shifts.*.ends_at' => ['required', 'date', 'after:shifts.*.starts_at'],
        ]);

        $result = DB::transaction(function () use ($data, $actor, $nit) {
            $employee = Employee::query()
                ->where('id', $data['employee_id'])
                ->where('company_nit', $nit)
                ->lockForUpdate()
                ->firstOrFail();

            $created = [];
            $skipped = [];

            foreach ($data['shifts'] as $row) {
                $startsAt = Carbon::parse($row['starts_at']);
                $endsAt = Carbon::parse($row['ends_at']);

                $overlap = EmployeeShift::query()
                    ->where('employee_id', $employee->id)
                    ->where('status', 'scheduled')
                    ->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt)
                    ->exists();

                if ($overlap) {
                    $skipped[] = [
                        'starts_at' => $startsAt->toIso8601String(),
                        'ends_at' => $endsAt->toIso8601String(),
                        'reason' => 'overlap',
                    ];

                    continue;
                }

                $shift = EmployeeShift::create([
                    'employee_id' => $employee->id,
                    'branch_id' => $data['branch_id'],
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => 'scheduled',
                    'created_by_user_id' => $actor->id,
                ]);

                $this->auditService->log('shift.created', $actor, $shift, [
                    'employee_id' => $employee->id,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'bulk' => true,
                ]);

                $created[] = $shift->fresh(['employee.position']);
            }

            return ['created' => $created, 'skipped' => $skipped];
        });

        return response()->json([
            'data' => [
                'created' => array_map(fn (EmployeeShift $s) => $this->toArray($s), $result['created']),
                'skipped' => $result['skipped'],
            ],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'shifts', 'update');
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);

        $data = $request->validate([
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'branch_id' => ['sometimes', 'uuid'],
        ]);

        $shift = DB::transaction(function () use ($id, $nit, $data, $actor) {
            $shift = EmployeeShift::query()
                ->whereHas('employee', fn ($q) => $q->where('company_nit', $nit))
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->status !== 'scheduled') {
                abort(422, 'Un turno cancelado no se puede editar; crea uno nuevo.');
            }

            $original = $shift->only(array_keys($data));
            $shift->fill($data);

            if ($shift->ends_at <= $shift->starts_at) {
                abort(422, 'ends_at debe ser posterior a starts_at.');
            }

            $shift->save();

            $this->auditService->log('shift.updated', $actor, $shift, [
                'changes' => collect($data)->map(fn ($v, $k) => [
                    'before' => $original[$k] ?? null,
                    'after' => $v,
                ])->all(),
            ]);

            return $shift->fresh(['employee.position']);
        });

        return response()->json(['data' => $this->toArray($shift)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'shifts', 'delete');
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);

        $data = $request->validate([
            'reason' => ['required', Rule::in(['sick', 'personal', 'emergency', 'other'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = DB::transaction(function () use ($id, $nit, $data, $actor) {
            $shift = EmployeeShift::query()
                ->whereHas('employee', fn ($q) => $q->where('company_nit', $nit))
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->status === 'cancelled') {
                abort(422, 'El turno ya está cancelado.');
            }

            $shift->update([
                'status' => 'cancelled',
                'cancellation_reason' => $data['reason'],
                'cancellation_note' => $data['note'] ?? null,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
            ]);

            $this->auditService->log('shift.cancelled', $actor, $shift, [
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
            ]);

            return $shift->fresh(['employee.position']);
        });

        return response()->json(['data' => $this->toArray($shift)]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'shifts', 'create');
        $nit = $this->activeCompanyNit($request);
        $actor = $this->actingUserOrFail($request);

        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'branch_id' => ['required', 'uuid'],
            'demand' => ['required', 'array', 'min:1'],
            'demand.*.starts_at' => ['required', 'date'],
            'demand.*.ends_at' => ['required', 'date', 'after:demand.*.starts_at'],
            'demand.*.position_slug' => ['nullable', 'string'],
        ]);

        $slots = array_map(fn ($s) => [
            'starts_at' => Carbon::parse($s['starts_at']),
            'ends_at' => Carbon::parse($s['ends_at']),
            'position_slug' => $s['position_slug'] ?? null,
        ], $data['demand']);

        $result = $this->suggestionService->suggestForWeek(
            companyNit: $nit,
            branchId: $data['branch_id'],
            weekStart: Carbon::parse($data['week_start'])->startOfDay(),
            demandSlots: $slots,
        );

        $this->auditService->log('shift.suggested', $actor, null, [
            'branch_id' => $data['branch_id'],
            'week_start' => $data['week_start'],
            'assigned' => count($result['suggestions']),
            'unassigned' => count($result['unassigned']),
            'warnings' => count($result['warnings']),
        ]);

        return response()->json(['data' => $result]);
    }

    /** @return array<string, mixed> */
    private function toArray(EmployeeShift $shift): array
    {
        return [
            'id' => $shift->id,
            'employee_id' => $shift->employee_id,
            'employee_name' => $shift->employee?->fullName(),
            'position' => $shift->employee?->position ? [
                'slug' => $shift->employee->position->slug,
                'label' => $shift->employee->position->label,
                'color' => $shift->employee->position->color,
            ] : null,
            'branch_id' => $shift->branch_id,
            'starts_at' => $shift->starts_at->toIso8601String(),
            'ends_at' => $shift->ends_at->toIso8601String(),
            'status' => $shift->status,
            'cancellation_reason' => $shift->cancellation_reason,
            'cancellation_note' => $shift->cancellation_note,
            'cancelled_at' => $shift->cancelled_at?->toIso8601String(),
        ];
    }
}
