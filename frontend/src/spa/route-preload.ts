/**
 * Preload de chunks de ruta en hover/focus (#269, Fase 4).
 *
 * El SPA usa `React.lazy()` (no loaders de React Router), por lo que el
 * `prefetch='intent'` de `<Link>` es **no-op** para el code-splitting. Aquí
 * implementamos el preload real: al pasar el mouse / enfocar un `AppLink`,
 * disparamos el `import()` del chunk de la ruta destino para que, al hacer
 * click, el módulo ya esté descargado y la página monte al instante (su query
 * de datos arranca de inmediato, en paralelo con nada).
 *
 * Combinado con el cache de React Query (Fase 3), una segunda visita a una
 * ruta ya cargada navega sin skeleton.
 *
 * IMPORTANTE: este mapa es **espejo de los `import()` lazy de `spa/router.tsx`**.
 * Si una ruta no está aquí, el preload simplemente no aplica (la navegación
 * cae al `RouteSkeleton` normal) — no rompe nada, solo no acelera. Mantener en
 * sync al agregar rutas pesadas nuevas. Las rutas eager (no lazy) se omiten.
 */
const ROUTE_CHUNKS: Record<string, () => Promise<unknown>> = {
    '/dashboard': () => import('@/pages/dashboard'),
    '/me': () => import('@/pages/me/index'),
    '/me/profile': () => import('@/pages/me/perfil'),
    '/me/schedule': () => import('@/pages/me/agenda'),
    '/suppliers': () => import('@/pages/suppliers/index'),
    '/loyalty/reports': () => import('@/pages/loyalty/reports'),
    '/billing': () => import('@/pages/billing/index'),
    '/clients': () => import('@/pages/clients/index'),
    '/coupons': () => import('@/pages/coupons/index'),
    '/menu': () => import('@/pages/menu/index'),
    '/company/metrics': () => import('@/pages/metrics/index'),
    '/company/reports': () => import('@/pages/reports/index'),
    '/chats': () => import('@/pages/chats'),
    '/inventory': () => import('@/pages/inventory/index'),
    '/purchases': () => import('@/pages/purchases/index'),
    '/orders/deliveries': () => import('@/pages/deliveries/index'),
    '/deliveries/metrics': () => import('@/pages/deliveries/metrics'),
    '/my-deliveries': () => import('@/pages/deliveries/mine'),
    '/employees': () => import('@/pages/employees/index'),
    '/employees/reports': () => import('@/pages/employees/reports'),
    '/kds': () => import('@/pages/kds/index'),
    '/orders/board': () => import('@/pages/orders/board'),
    '/orders/tables': () => import('@/pages/orders/tables/index'),
    '/orders/table-sessions': () => import('@/pages/orders/table-sessions/index'),
    '/orders/cashier': () => import('@/pages/caja/index'),
    '/identities/roles': () => import('@/pages/roles/roles'),
    '/identities/users': () => import('@/pages/users/users'),
    '/company/branches': () => import('@/pages/company/branches/index'),
    '/company/kds': () => import('@/pages/company/kds'),
    '/company/preferences': () => import('@/pages/company/preferences'),
    '/company/printers': () => import('@/pages/company/printers'),
    '/company/settings': () => import('@/pages/company/settings'),
    '/company/tables': () => import('@/pages/company/tables/index'),
    '/company/warehouses': () => import('@/pages/company/warehouses/index'),
    '/company/whatsapp': () => import('@/pages/company/whatsapp'),
    '/company/dian': () => import('@/pages/company/dian'),
    '/dian/documents': () => import('@/pages/dian/documents'),
    '/planner': () => import('@/pages/planner/week'),
    '/settings/appearance': () => import('@/pages/settings/appearance'),
    '/settings/notifications': () => import('@/pages/settings/notifications'),
    '/settings/profile': () => import('@/pages/settings/profile'),
    '/settings/password': () => import('@/pages/settings/password'),
};

/** Rutas ya preloaded — evita re-disparar el import en cada hover. */
const preloaded = new Set<string>();

/**
 * Dispara el preload del chunk de `href` (idempotente). Tolera querystring y
 * trailing slash. Si falla la descarga, se desmarca para reintentar luego.
 */
export function preloadRoute(href: string): void {
    if (!href || href === '#') {
        return;
    }
    const path = href.split('?')[0].split('#')[0].replace(/\/+$/, '') || '/';
    if (preloaded.has(path)) {
        return;
    }
    const loader = ROUTE_CHUNKS[path];
    if (!loader) {
        return;
    }
    preloaded.add(path);
    void loader().catch(() => {
        preloaded.delete(path);
    });
}
