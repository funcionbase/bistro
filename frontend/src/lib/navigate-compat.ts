import { BOOTSTRAP_QUERY_KEY } from '@/hooks/use-bootstrap';
import { BUSINESS_CONTEXT_QUERY_KEY } from '@/lib/business-context';
import { queryClient } from './query-client';

/**
 * Refresca el contexto compartido (empresa, sedes, permisos) tras una
 * mutación que lo cambia — invalida el query de `/api/v1/bootstrap` y, en
 * cascada, el de `/api/v1/me/active-context` para que las capabilities
 * y labels del vertical de la sede activa se recalculen también.
 *
 * Es crítico invalidar ambas: el switch de sede cambia tanto los permisos
 * (bootstrap) como el tipo de negocio de la sede (business context), y el
 * sidebar/UI dependen de las dos fuentes para mostrar las opciones correctas.
 *
 * Para navegación usar `useNavigate` de React Router (`<AppLink>` para
 * navegación declarativa).
 *
 * Devuelve una promesa que resuelve cuando los refetch ACTIVOS terminan. Si vas
 * a navegar a una ruta cuyo guard lee este contexto (p.ej. el guard de
 * `/enrollment/company` lee `needsProfileCompletion`), DEBES `await` antes de
 * navegar: sin esperar, el guard monta con el cache viejo y puede rebotar.
 */
export function reloadContext(): Promise<void> {
    return Promise.all([
        queryClient.invalidateQueries({ queryKey: BOOTSTRAP_QUERY_KEY }),
        queryClient.invalidateQueries({ queryKey: BUSINESS_CONTEXT_QUERY_KEY }),
    ]).then(() => undefined);
}
