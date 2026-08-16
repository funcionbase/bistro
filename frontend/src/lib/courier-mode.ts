/**
 * Detecta si el usuario opera en "courier-only mode".
 *
 * Espejo del helper backend `App\Support\PostLoginRedirect` — mantener
 * ambas listas sincronizadas. Un usuario en courier mode:
 *  - Tiene `deliveries.self_assign` (puede tomar entregas).
 *  - NO tiene ninguno de los `FULL_NAV_PERMISSIONS` (no es admin, owner,
 *    cashier, cook con privilegios extra, etc.).
 *
 * Cuando el modo está activo:
 *  - El sidebar oculta Dashboard, Menú, Mesas, Tablero, Domicilios admin
 *    y secciones de operaciones/reportes/admin. Solo deja "Mis entregas".
 *  - Tras login, el redirect default es `/my-deliveries` en lugar de
 *    `/dashboard`.
 */

const COURIER_PERMISSION = 'deliveries.self_assign';

const FULL_NAV_PERMISSIONS = [
    'reports.read',
    'company.update',
    'orders.create',
    'menu.update',
    'inventory.read',
    'shifts.read',
    // kds.read no convierte al usuario en courier-only:
    // si tiene acceso al KDS quiere decir que está en cocina, no en moto.
    'kds.read',
] as const;

export function isCourierOnlyMode(permissions: readonly string[] | undefined, isSystemRole = false): boolean {
    if (isSystemRole) {
        return false;
    }
    if (!permissions || permissions.length === 0) {
        return false;
    }
    if (!permissions.includes(COURIER_PERMISSION)) {
        return false;
    }
    return !FULL_NAV_PERMISSIONS.some((slug) => permissions.includes(slug));
}
