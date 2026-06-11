import { OnboardingPageSkeleton } from '@/components/ui/onboarding-page-skeleton';
import { useBootstrap } from '@/hooks/use-bootstrap';
import { type ReactNode } from 'react';
import { Navigate } from 'react-router-dom';

/**
 * Guard del paso "Registrar empresa" del enrollment (`/enrollment/company`).
 *
 * Quien registra la empresa queda como Propietario; por eso solo puede llegar
 * aquí el usuario que ya completó el paso personal (`/enrollment/user`). Sin
 * este guard se podría abrir `/enrollment/company` directo, llenar todo el
 * formulario y recibir un 422 al enviar — `CompanyEnrollmentController` ya
 * rechaza la empresa si el usuario no está `active`, así que el dato nunca se
 * corrompe (no hay empresas huérfanas), pero la UX queda en un callejón.
 *
 * Reglas:
 * - Sin sesión / bootstrap falla → `/` (landing).
 * - `needsProfileCompletion`     → `/enrollment/user` (falta el paso personal).
 * - Perfil completo              → renderiza el paso de empresa.
 */
export function EnrollmentCompanyGuard({ children }: { children: ReactNode }) {
    const { data, isLoading, isError } = useBootstrap();

    if (isLoading) {
        return <OnboardingPageSkeleton layout="form" />;
    }

    if (isError || !data?.auth.user) {
        return <Navigate to="/" replace />;
    }

    if (data.needsProfileCompletion) {
        return <Navigate to="/enrollment/user" replace />;
    }

    return <>{children}</>;
}
