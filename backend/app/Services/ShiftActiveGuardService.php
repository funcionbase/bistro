<?php

namespace App\Services;

use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/**
 * Verifica que el usuario tenga un turno activo en la sede + empresa actuales
 * antes de operar la caja.
 *
 * "Turno activo" = existe un `employee_shift` en estado `scheduled` (no
 * cancelled), del empleado vinculado al usuario, en la sede indicada, cuya
 * ventana [starts_at, ends_at] contenga el instante actual.
 *
 *  - Owners (Propietario) y Administradores bypasean — su acceso a caja
 *    sigue gobernado por permisos (employees.* / orders.*). La razón: estos
 *    roles asumen responsabilidad supervisoria y deben poder operar caja
 *    fuera de turno (emergencias, cobertura, auditoría in-situ). Los
 *    colaboradores con rol "Empleado" o roles custom (Cocina, Domiciliario,
 *    etc.) SÍ requieren turno activo.
 *  - Empleados sin perfil `employees` fallan: no se puede confirmar su turno.
 */
class ShiftActiveGuardService
{
    /**
     * Lanza AuthorizationException si el usuario no tiene turno activo y no
     * es owner/admin de la empresa.
     *
     * @throws AuthorizationException
     */
    public function assertActiveShift(User $user, string $companyNit, string $branchId): void
    {
        if ($this->bypassActiveShift($user, $companyNit)) {
            return;
        }

        if (! $this->hasActiveShift($user, $companyNit, $branchId, Carbon::now())) {
            throw new AuthorizationException('No tienes turno activo en esta sede a esta hora.');
        }
    }

    public function hasActiveShift(User $user, string $companyNit, string $branchId, Carbon $now): bool
    {
        $employee = Employee::query()
            ->where('company_nit', $companyNit)
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->first();

        if ($employee === null) {
            return false;
        }

        return EmployeeShift::query()
            ->where('employee_id', $employee->id)
            ->where('branch_id', $branchId)
            ->where('status', 'scheduled')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->exists();
    }

    /**
     * Determina si el usuario tiene un rol del sistema que le permite
     * operar caja sin turno activo: Propietario o Administrador.
     *
     * Filtra por NOMBRE además de `is_system`, porque los 3 roles canónicos
     * (Propietario / Administrador / Empleado) son todos `is_system=true`.
     * Sin el filtro por nombre, también el empleado bypasearía.
     */
    private function bypassActiveShift(User $user, string $companyNit): bool
    {
        $bypassRoleNames = array_filter([
            config('roles.role_names.owner', 'Propietario'),
            config('roles.role_names.admin', 'Administrador'),
        ]);

        return CompanyUser::query()
            ->where('user_id', $user->id)
            ->where('company_nit', $companyNit)
            ->whereHas('role', fn ($q) => $q->where('is_system', true)->whereIn('name', $bypassRoleNames))
            ->exists();
    }
}
