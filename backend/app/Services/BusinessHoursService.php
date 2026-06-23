<?php

namespace App\Services;

use App\Models\BusinessHour;
use App\Models\BusinessHourException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Resuelve el estado de apertura del restaurante aplicando la lógica de precedencia de excepciones.
 *
 * Precedencia: BusinessHourException del día > BusinessHour base del día de la semana.
 * day_of_week: 0 = domingo, 6 = sábado (convención de Carbon/JS Date, diferente al 0=lunes de la app).
 * Si no hay horarios definidos, el restaurante se considera cerrado.
 * La zona horaria se toma de config business-hours.timezone; si no está definida, usa app.timezone.
 *
 * Por SEDE: horarios y excepciones son por sede (UNIQUE company_nit+branch_id+...). Los métodos
 * aceptan un $branchId opcional. Cuando se pasa, las consultas se filtran a esa sede de forma
 * determinista (independiente del BranchScope del request); cuando es null, se evalúa a nivel
 * empresa (comportamiento usado por flujos administrativos donde el BranchScope ya filtra por la
 * sede activa). Los flujos públicos/bot DEBEN pasar la sede: ahí no hay active_branch_id y sin el
 * filtro explícito se tomaría una sede arbitraria, cerrando o abriendo otra sede por error.
 */
class BusinessHoursService
{
    private string $timezone;

    public function __construct()
    {
        $this->timezone = (string) config('business-hours.timezone', config('app.timezone', 'UTC'));
    }

    /**
     * Full status snapshot for a company (optionally scoped to a branch) at a given moment.
     *
     * @return array{
     *     is_open: bool,
     *     reason: string,
     *     exception_active: bool,
     *     menu_available: bool,
     *     menu_visibility_reason: string,
     *     current_time: string,
     *     next_opening: array{day: string, time: string}|null
     * }
     */
    public function getCurrentStatus(string $companyNit, ?Carbon $at = null, ?string $branchId = null): array
    {
        $at = ($at ?? Carbon::now())->setTimezone($this->timezone);

        $exception = $this->getTodayException($companyNit, $at, $branchId);

        if ($exception !== null) {
            if (! $exception->is_open) {
                return [
                    'is_open' => false,
                    'reason' => 'closed_by_exception',
                    'exception_active' => true,
                    'menu_available' => false,
                    'menu_visibility_reason' => 'exception_closed',
                    'current_time' => $at->format('H:i'),
                    'next_opening' => $this->getNextOpening($companyNit, $at, $branchId),
                ];
            }

            $currentTime = $at->format('H:i:s');
            $isOpen = $exception->open_time !== null
                && $exception->close_time !== null
                && $currentTime >= $exception->open_time
                && $currentTime < $exception->close_time;

            return [
                'is_open' => $isOpen,
                'reason' => $isOpen ? 'open_by_exception' : 'not_in_service_window',
                'exception_active' => true,
                'menu_available' => $isOpen,
                'menu_visibility_reason' => $isOpen ? 'visible' : 'not_in_service_window',
                'current_time' => $at->format('H:i'),
                'next_opening' => $isOpen ? null : $this->getNextOpening($companyNit, $at, $branchId),
            ];
        }

        $hasAnyHours = $this->hoursQuery($companyNit, $branchId)->exists();

        if (! $hasAnyHours) {
            return [
                'is_open' => false,
                'reason' => 'no_schedule_defined',
                'exception_active' => false,
                'menu_available' => false,
                'menu_visibility_reason' => 'restaurant_closed',
                'current_time' => $at->format('H:i'),
                'next_opening' => null,
            ];
        }

        $baseHour = $this->getBaseHoursForDay($companyNit, $at->dayOfWeek, $branchId);

        if ($baseHour === null || ! $baseHour->is_enabled) {
            return [
                'is_open' => false,
                'reason' => 'out_of_hours',
                'exception_active' => false,
                'menu_available' => false,
                'menu_visibility_reason' => 'restaurant_closed',
                'current_time' => $at->format('H:i'),
                'next_opening' => $this->getNextOpening($companyNit, $at, $branchId),
            ];
        }

        $currentTime = $at->format('H:i:s');
        $isOpen = $baseHour->open_time !== null
            && $baseHour->close_time !== null
            && $currentTime >= $baseHour->open_time
            && $currentTime < $baseHour->close_time;

        return [
            'is_open' => $isOpen,
            'reason' => $isOpen ? 'within_hours' : 'out_of_hours',
            'exception_active' => false,
            'menu_available' => $isOpen,
            'menu_visibility_reason' => $isOpen ? 'visible' : 'not_in_service_window',
            'current_time' => $at->format('H:i'),
            'next_opening' => $isOpen ? null : $this->getNextOpening($companyNit, $at, $branchId),
        ];
    }

    public function isOpenNow(string $companyNit, ?Carbon $at = null, ?string $branchId = null): bool
    {
        return $this->getCurrentStatus($companyNit, $at, $branchId)['is_open'];
    }

    public function isMenuAvailableNow(string $companyNit, ?Carbon $at = null, ?string $branchId = null): bool
    {
        return $this->getCurrentStatus($companyNit, $at, $branchId)['menu_available'];
    }

    public function getTodayException(string $companyNit, Carbon $date, ?string $branchId = null): ?BusinessHourException
    {
        return $this->exceptionQuery($companyNit, $branchId)
            ->forDate($date)
            ->first();
    }

    public function getBaseHoursForDay(string $companyNit, int $dayOfWeek, ?string $branchId = null): ?BusinessHour
    {
        return $this->hoursQuery($companyNit, $branchId)
            ->forDay($dayOfWeek)
            ->first();
    }

    /**
     * @return array{day: string, time: string}|null
     */
    public function getNextOpening(string $companyNit, ?Carbon $from = null, ?string $branchId = null): ?array
    {
        $from = ($from ?? Carbon::now())->setTimezone($this->timezone);

        $dayNames = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

        $baseHours = $this->hoursQuery($companyNit, $branchId)
            ->enabled()
            ->whereNotNull('open_time')
            ->whereNotNull('close_time')
            ->get()
            ->keyBy('day_of_week');

        // Check up to 7 days ahead
        for ($daysAhead = 0; $daysAhead <= 7; $daysAhead++) {
            $candidate = $from->copy()->addDays($daysAhead);
            $dow = $candidate->dayOfWeek;

            // Check for exception on this date
            $exception = $this->getTodayException($companyNit, $candidate, $branchId);

            if ($exception !== null) {
                if ($exception->is_open && $exception->open_time !== null) {
                    $candidateTime = $candidate->format('H:i:s');
                    if ($daysAhead > 0 || $candidateTime < $exception->open_time) {
                        return [
                            'day' => $dayNames[$dow],
                            'time' => substr($exception->open_time, 0, 5),
                        ];
                    }
                }

                continue;
            }

            /** @var BusinessHour|null $baseHour */
            $baseHour = $baseHours->get($dow);
            if ($baseHour === null) {
                continue;
            }

            $candidateTime = $candidate->format('H:i:s');
            if ($daysAhead > 0 || $candidateTime < $baseHour->open_time) {
                return [
                    'day' => $dayNames[$dow],
                    'time' => substr($baseHour->open_time, 0, 5),
                ];
            }
        }

        return null;
    }

    /**
     * Query base de horarios. Con $branchId escapa el BranchScope y filtra
     * explícitamente esa sede (determinista en flujos públicos/bot/cron sin
     * active_branch_id). Sin $branchId, query normal (el BranchScope del
     * request, si lo hay, filtra por la sede activa).
     *
     * @return Builder<BusinessHour>
     */
    private function hoursQuery(string $companyNit, ?string $branchId): Builder
    {
        $query = $branchId !== null
            ? BusinessHour::withoutBranchScope()->where('branch_id', $branchId)
            : BusinessHour::query();

        return $query->forCompany($companyNit);
    }

    /**
     * Query base de excepciones, con la misma semántica de $branchId que hoursQuery().
     *
     * @return Builder<BusinessHourException>
     */
    private function exceptionQuery(string $companyNit, ?string $branchId): Builder
    {
        $query = $branchId !== null
            ? BusinessHourException::withoutBranchScope()->where('branch_id', $branchId)
            : BusinessHourException::query();

        return $query->forCompany($companyNit);
    }
}
