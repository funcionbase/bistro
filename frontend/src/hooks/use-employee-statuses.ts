import { useSharedData } from '@/lib/shared-data';
import type { EmployeeStatus, EmployeeStatusBadge, EmployeeStatusesConfig } from '@/types';

/**
 * Fallback embebido si el contexto global de la SPA no está disponible aún
 * (e.g., primer render antes del bootstrap, pantallas sin JWT).
 *
 * Debe coincidir con `config/employees.php`. Cualquier cambio aquí debe
 * replicarse allá. Ver `application/constants/EMPLOYEE_STATUSES.md`.
 */
export const EMPLOYEE_STATUSES_FALLBACK: EmployeeStatusesConfig = {
    statuses: ['active', 'inactive', 'vacation', 'sick_leave', 'compensatory'],
    absence_statuses: ['vacation', 'sick_leave', 'compensatory'],
    labels: {
        active: 'Activo',
        inactive: 'Inactivo',
        vacation: 'Vacaciones',
        sick_leave: 'Incapacidad',
        compensatory: 'Compensatorio',
    },
    badges: {
        active: 'safe',
        inactive: 'critical',
        vacation: 'warning',
        sick_leave: 'warning',
        compensatory: 'warning',
    },
};

/**
 * Hook para consumir el catálogo canónico de vinculation_status.
 * Lee desde el contexto global de la SPA con fallback embebido.
 */
export function useEmployeeStatuses(): EmployeeStatusesConfig {
    const page = { props: useSharedData() };
    return page.props.employeeStatuses ?? EMPLOYEE_STATUSES_FALLBACK;
}

export function employeeStatusLabel(config: EmployeeStatusesConfig | undefined, status: string): string {
    const cfg = config ?? EMPLOYEE_STATUSES_FALLBACK;
    return cfg.labels[status as EmployeeStatus] ?? status;
}

export function employeeStatusBadge(config: EmployeeStatusesConfig | undefined, status: string): EmployeeStatusBadge {
    const cfg = config ?? EMPLOYEE_STATUSES_FALLBACK;
    return cfg.badges[status as EmployeeStatus] ?? 'secondary';
}

export function isAbsenceStatus(config: EmployeeStatusesConfig | undefined, status: string): boolean {
    const cfg = config ?? EMPLOYEE_STATUSES_FALLBACK;
    return cfg.absence_statuses.includes(status as EmployeeStatus);
}
