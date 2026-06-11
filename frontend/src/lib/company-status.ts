/**
 * Fuente única de verdad de `companies.status` en el frontend.
 *
 * Espejo de `application/config/companies.php` — cualquier cambio acá debe
 * replicarse allá (buckets, labels, transiciones). Ver
 * `application/constants/COMPANY_STATUSES.md` para la doctrina.
 *
 * Hoy el catálogo vive solo en este módulo porque `activeCompany.status` ya
 * llega en el contexto global de la SPA — pero si en el futuro se necesita
 * exponer el catálogo completo a la UI (e.g., reportes que listen empresas
 * filtradas por bucket), agregar `companyStatuses` al endpoint de bootstrap y
 * que este módulo lo prefiera sobre el fallback.
 */

/**
 * Lista cerrada del enum BD. Debe coincidir con `config('companies.all')` y
 * con el CHECK constraint de la migración foundation.
 */
export type CompanyStatus = 'pending_activation' | 'active' | 'past_due' | 'suspended' | 'rejected' | 'inactive';

/**
 * Variantes del componente Badge (shadcn + DS) que aplican a este dominio.
 */
export type CompanyStatusBadgeVariant = 'safe' | 'warning' | 'critical' | 'secondary' | 'destructive';

export interface CompanyStatusCatalog {
    /** Lista cerrada del enum. */
    all: CompanyStatus[];
    /** Estados onboardeados — pasan `EnsureCompanyVerified`. */
    verified: CompanyStatus[];
    /** Estados esperando verificación inicial. */
    pending: CompanyStatus[];
    /** Estados terminales o semi-terminales. */
    blocked: CompanyStatus[];
    /** Estados con bloqueo comercial total (sólo /billing accesible). */
    fully_blocked: CompanyStatus[];
    /** Etiquetas en es-CO. */
    labels: Record<CompanyStatus, string>;
    /** Variante de badge sugerida por estado. */
    badges: Record<CompanyStatus, CompanyStatusBadgeVariant>;
}

/**
 * Fallback embebido. Debe coincidir con `config/companies.php`. Cualquier
 * cambio acá replicarlo allá. Ver `application/constants/COMPANY_STATUSES.md`.
 */
export const COMPANY_STATUS_FALLBACK: CompanyStatusCatalog = {
    all: ['pending_activation', 'active', 'past_due', 'suspended', 'rejected', 'inactive'],
    verified: ['active', 'past_due', 'suspended'],
    pending: ['pending_activation'],
    blocked: ['rejected', 'inactive'],
    fully_blocked: ['suspended'],
    labels: {
        pending_activation: 'Pendiente de verificación',
        active: 'Activa',
        past_due: 'En mora',
        suspended: 'Suspendida',
        rejected: 'Rechazada',
        inactive: 'Inactiva',
    },
    badges: {
        pending_activation: 'secondary',
        active: 'safe',
        past_due: 'warning',
        suspended: 'critical',
        rejected: 'destructive',
        inactive: 'secondary',
    },
};

export function companyStatusLabel(status: string): string {
    return (COMPANY_STATUS_FALLBACK.labels as Record<string, string>)[status] ?? status;
}

export function companyStatusBadgeVariant(status: string): CompanyStatusBadgeVariant {
    return (COMPANY_STATUS_FALLBACK.badges as Record<string, CompanyStatusBadgeVariant>)[status] ?? 'secondary';
}

/** Empresa onboardeada — gate `EnsureCompanyVerified`. */
export function isVerified(status: string): boolean {
    return (COMPANY_STATUS_FALLBACK.verified as string[]).includes(status);
}

/** Empresa esperando verificación inicial. */
export function isPendingVerification(status: string): boolean {
    return (COMPANY_STATUS_FALLBACK.pending as string[]).includes(status);
}

/** Empresa rechazada o inactiva — no puede operar. */
export function isBlocked(status: string): boolean {
    return (COMPANY_STATUS_FALLBACK.blocked as string[]).includes(status);
}

/** Bloqueo comercial total — gate `EnsureCompanyNotBlocked` rechaza casi todo. */
export function isFullyBlocked(status: string): boolean {
    return (COMPANY_STATUS_FALLBACK.fully_blocked as string[]).includes(status);
}

/** Empresa seleccionable en el company-selector (= bucket `verified`). */
export function isSelectable(status: string): boolean {
    return isVerified(status);
}
