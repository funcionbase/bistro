/**
 * Un usuario con rol NO-sistema y CERO sedes en `branch_users` no puede operar:
 * caja, comandas, inventario, entregas y reportes filtran por sede
 * (`EnsureBranchAccess` devuelve 422 NO_ACTIVE_BRANCH sin sede).
 *
 * Los roles de sistema (owner/admin, `is_system=true`) acceden a todas las
 * sedes por bypass, así que nunca caen en este estado.
 *
 * Cuando es true, el sidebar se reduce a "Dashboard" y el dashboard muestra un
 * mensaje pidiendo al usuario que su gerente/dueño le asigne una sede. Espejo
 * conceptual de `isCourierOnlyMode` en `lib/courier-mode.ts`.
 */
export function hasNoBranchAssigned(isSystemRole: boolean, branchCount: number): boolean {
    return !isSystemRole && branchCount === 0;
}
