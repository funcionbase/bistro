import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { route } from '@/lib/route-compat';
import { useSharedData } from '@/lib/shared-data';
import { MapPin } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

/**
 * Banner global de configuración de sede activa.
 *
 * Cubre tres estados terminales donde el usuario llegó al app autenticado
 * pero la sesión NO puede operar contra una sede concreta. Sin sede activa,
 * todos los endpoints que pasan por `EnsureBranchAccess` devuelven `422
 * NO_ACTIVE_BRANCH` y la UI queda llena de banners de "Conexión perdida"
 * o "Sede activa fuera de fecha" que no le dicen al usuario qué hacer.
 *
 * Estados:
 *  1. **Empresa sin sedes** (`branches.length === 0`):
 *     - Si el usuario puede crear sedes (owner o permiso `branches.manage`):
 *       CTA `Crear primera sede` → `/company/branches`.
 *     - Si no puede: mensaje sin CTA pidiendo contactar al administrador.
 *  2. **Hay sedes pero JWT sin `active_branch_id`**: CTA al selector de
 *     sede para que el usuario elija una.
 *  3. **No aplica** (`hasBranch === true`): el componente devuelve `null`.
 *
 * No detecta el caso "sede stale" (JWT con id de sede archivada/borrada).
 * Para eso el flujo correcto es reautenticación — los banners de pedidos
 * pueden seguir mostrando un fallback si lo detectan vía `BRANCH_*` code.
 */
export default function MissingBranchBanner() {
    const navigate = useNavigate();
    const { activeBranch, activeCompany, branches, role, permissions } = useSharedData();

    const hasBranch = Boolean(activeBranch?.id);
    if (hasBranch) {
        return null;
    }

    // Si la empresa está bloqueada por mora, el SuspendedBanner ya domina
    // la página con su CTA "Ir a Facturación". Apilar un "Crear primera sede"
    // encima sería ruido contradictorio (no podrías crear sede aunque
    // quisieras — el middleware bloquea /company/branches). El banner de
    // sede sólo es accionable cuando la empresa está operativa.
    if (activeCompany?.status === 'suspended') {
        return null;
    }

    const branchCount = (branches ?? []).length;
    const isSystem = role?.is_system === true;
    const canManageBranches = isSystem || (permissions ?? []).includes('branches.manage');

    if (branchCount === 0) {
        return (
            <div className="px-4 pt-4 sm:px-6 md:px-8">
                <Alert variant="warning" role="alert" aria-live="polite">
                    <MapPin className="h-5 w-5" />
                    <AlertTitle>Esta empresa aún no tiene sedes</AlertTitle>
                    <AlertDescription className="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            {canManageBranches
                                ? 'Crea tu primera sede para empezar a operar (caja, mesas, inventario y reportes funcionan por sede).'
                                : 'Pide a un administrador de la empresa que cree la primera sede para que puedas operar.'}
                        </span>
                        {canManageBranches && (
                            <Button size="sm" className="shrink-0" onClick={() => navigate(route('company.branches'))}>
                                Crear primera sede
                            </Button>
                        )}
                    </AlertDescription>
                </Alert>
            </div>
        );
    }

    return (
        <div className="px-4 pt-4 sm:px-6 md:px-8">
            <Alert variant="warning" role="alert" aria-live="polite">
                <MapPin className="h-5 w-5" />
                <AlertTitle>Selecciona una sede activa</AlertTitle>
                <AlertDescription className="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        Tu sesión no tiene una sede activa. Elige una para empezar a operar — la caja, mesas, inventario y reportes filtran por sede.
                    </span>
                    <Button size="sm" variant="outline" className="shrink-0" onClick={() => navigate(route('auth.branch-selector'))}>
                        Elegir sede
                    </Button>
                </AlertDescription>
            </Alert>
        </div>
    );
}
