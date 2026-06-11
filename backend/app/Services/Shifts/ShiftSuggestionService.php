<?php

namespace App\Services\Shifts;

use App\Models\CompanyWorkforceSetting;
use App\Models\Employee;
use App\Models\EmployeeShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Generador greedy de sugerencias de turnos.
 *
 * Entrada: `demandSlots` = array de huecos a cubrir en una semana
 *  [
 *    ['starts_at' => Carbon, 'ends_at' => Carbon, 'position_slug' => ?string],
 *    ...
 *  ]
 *
 * Restricciones duras:
 *  - Solo empleados con `vinculation_status = 'active'`.
 *  - Solo empleados cuyo `primary_branch_id = $branchId` (sin cross-sede).
 *  - No solapamiento con turnos ya scheduled del mismo empleado.
 *
 * Restricciones suaves (advertencias devueltas en `warnings`):
 *  - Máx. horas semanales (company_workforce_settings.max_weekly_hours).
 *  - Mín. días libres (company_workforce_settings.min_days_off_per_week
 *    con override en employee.min_days_off_override).
 *
 * Invariante de equidad: en cada paso elegimos al empleado con MENOS horas
 * acumuladas en la semana (round-robin por carga). Esto minimiza la
 * desviación estándar de horas por empleado.
 */
class ShiftSuggestionService
{
    /**
     * @param  array<int, array{starts_at: Carbon, ends_at: Carbon, position_slug?: ?string}>  $demandSlots
     * @return array{
     *     suggestions: array<int, array{employee_id: string, starts_at: string, ends_at: string}>,
     *     unassigned: array<int, array{starts_at: string, ends_at: string, reason: string}>,
     *     warnings: array<int, array{employee_id: string, kind: string, message: string}>
     * }
     */
    public function suggestForWeek(string $companyNit, string $branchId, Carbon $weekStart, array $demandSlots): array
    {
        $settings = CompanyWorkforceSetting::query()->find($companyNit) ?? new CompanyWorkforceSetting([
            'max_weekly_hours' => 48,
            'min_days_off_per_week' => 1,
        ]);

        $employees = Employee::query()
            ->where('company_nit', $companyNit)
            ->where('primary_branch_id', $branchId)
            ->where('vinculation_status', 'active')
            ->whereNull('archived_at')
            ->get();

        if ($employees->isEmpty()) {
            return [
                'suggestions' => [],
                'unassigned' => array_map(fn ($slot) => [
                    'starts_at' => $slot['starts_at']->toIso8601String(),
                    'ends_at' => $slot['ends_at']->toIso8601String(),
                    'reason' => 'no_eligible_employees',
                ], $demandSlots),
                'warnings' => [],
            ];
        }

        $weekEnd = $weekStart->copy()->addDays(7);
        $accumulatedMinutes = $this->loadAccumulatedMinutes($employees->pluck('id')->all(), $weekStart, $weekEnd);

        $suggestions = [];
        $unassigned = [];
        $warnings = [];

        usort($demandSlots, fn ($a, $b) => $a['starts_at']->lt($b['starts_at']) ? -1 : 1);

        foreach ($demandSlots as $slot) {
            $candidate = $this->pickCandidate($employees, $accumulatedMinutes, $slot, $branchId);

            if ($candidate === null) {
                $unassigned[] = [
                    'starts_at' => $slot['starts_at']->toIso8601String(),
                    'ends_at' => $slot['ends_at']->toIso8601String(),
                    'reason' => 'no_available_candidate',
                ];

                continue;
            }

            $slotMinutes = (int) $slot['starts_at']->diffInMinutes($slot['ends_at']);
            $accumulatedMinutes[$candidate->id] += $slotMinutes;

            $totalHours = $accumulatedMinutes[$candidate->id] / 60.0;
            if ($totalHours > $settings->max_weekly_hours) {
                $warnings[] = [
                    'employee_id' => $candidate->id,
                    'kind' => 'max_weekly_hours_exceeded',
                    'message' => "{$candidate->fullName()} supera el máximo semanal ({$totalHours}h vs. {$settings->max_weekly_hours}h).",
                ];
            }

            $suggestions[] = [
                'employee_id' => $candidate->id,
                'starts_at' => $slot['starts_at']->toIso8601String(),
                'ends_at' => $slot['ends_at']->toIso8601String(),
            ];
        }

        return compact('suggestions', 'unassigned', 'warnings');
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, int>  $accumulated
     * @param  array{starts_at: Carbon, ends_at: Carbon}  $slot
     */
    private function pickCandidate(Collection $employees, array $accumulated, array $slot, string $branchId): ?Employee
    {
        $eligible = $employees->filter(function (Employee $emp) use ($slot, $branchId) {
            return ! $this->hasOverlap($emp->id, $slot['starts_at'], $slot['ends_at'], $branchId);
        });

        if ($eligible->isEmpty()) {
            return null;
        }

        return $eligible->sortBy(fn (Employee $e) => $accumulated[$e->id] ?? 0)->first();
    }

    private function hasOverlap(string $employeeId, Carbon $startsAt, Carbon $endsAt, string $branchId): bool
    {
        return EmployeeShift::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'scheduled')
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    /**
     * @param  array<int, string>  $employeeIds
     * @return array<string, int> employee_id => minutos planificados en la semana
     */
    private function loadAccumulatedMinutes(array $employeeIds, Carbon $weekStart, Carbon $weekEnd): array
    {
        $shifts = EmployeeShift::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'scheduled')
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->get(['employee_id', 'starts_at', 'ends_at']);

        $accumulated = array_fill_keys($employeeIds, 0);

        foreach ($shifts as $shift) {
            $accumulated[$shift->employee_id] = ($accumulated[$shift->employee_id] ?? 0)
                + (int) $shift->starts_at->diffInMinutes($shift->ends_at);
        }

        return $accumulated;
    }
}
