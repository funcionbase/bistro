/**
 * Mapeo URL → cadena de breadcrumb, homologado con la jerarquía del
 * sidebar (app-sidebar.tsx). Cada entrada declara su `parent` para
 * construir la cadena hacia el root Dashboard.
 *
 * Para detail pages dinámicas (`/employees/:id`, `/menu/:id`), el último
 * crumb usa el `title` del PageShell — no se hardcodea aquí.
 */

export interface BreadcrumbRoute {
    /** Pattern de path con segmentos dinámicos como `:id`. */
    pattern: string;
    /** Label estático del crumb. Si es null el último crumb usa `leafLabel`. */
    label: string | null;
    /** Path del crumb padre (otra pattern). null = root, va directo bajo "Dashboard". */
    parent: string | null;
}

export const BREADCRUMB_ROUTES: BreadcrumbRoute[] = [
    { pattern: '/dashboard', label: 'Dashboard', parent: null },

    { pattern: '/orders/cashier', label: 'Caja', parent: null },
    { pattern: '/orders/tables', label: 'Mesas', parent: null },
    { pattern: '/orders/table-sessions/:id', label: null, parent: '/orders/tables' },
    // /orders/table-sessions (lista) redirige a /orders/tables — sin entrada propia.
    { pattern: '/orders/board', label: 'Tablero', parent: null },
    { pattern: '/kds', label: 'Cocina (KDS)', parent: null },
    { pattern: '/kds/:stationId', label: null, parent: '/kds' },
    { pattern: '/orders/deliveries', label: 'Ventas del día', parent: null },
    { pattern: '/deliveries/mine', label: 'Mis entregas', parent: null },

    { pattern: '/menu', label: 'Menú', parent: null },
    { pattern: '/menu/:id', label: null, parent: '/menu' },

    { pattern: '/clients', label: 'Clientes', parent: '/contactos' },
    { pattern: '/clients/:id', label: null, parent: '/clients' },
    { pattern: '/coupons', label: 'Cupones', parent: '/contactos' },
    { pattern: '/coupons/:id', label: null, parent: '/coupons' },
    { pattern: '/loyalty/reports', label: 'Fidelización', parent: '/contactos' },

    { pattern: '/inventory', label: 'Existencias', parent: '/inventario' },
    { pattern: '/purchases', label: 'Compras', parent: '/inventario' },
    { pattern: '/purchases/:id', label: null, parent: '/purchases' },
    { pattern: '/suppliers', label: 'Proveedores', parent: '/inventario' },
    { pattern: '/suppliers/:id', label: null, parent: '/suppliers' },
    { pattern: '/company/warehouses', label: 'Bodegas', parent: '/inventario' },

    { pattern: '/company/metrics', label: 'Métricas', parent: '/analisis' },
    { pattern: '/company/reports', label: 'Informes', parent: '/analisis' },
    { pattern: '/deliveries/metrics', label: 'Métricas de domicilios', parent: '/analisis' },
    { pattern: '/dian/documents', label: 'Documentos DIAN', parent: '/analisis' },

    { pattern: '/identities/users', label: 'Usuarios', parent: '/identidades' },
    { pattern: '/identities/roles', label: 'Roles', parent: '/identidades' },

    { pattern: '/employees', label: 'Empleados', parent: '/colaboradores' },
    { pattern: '/employees/create', label: 'Crear colaborador', parent: '/employees' },
    { pattern: '/employees/:id', label: null, parent: '/employees' },
    { pattern: '/planner/week', label: 'Planificador', parent: '/colaboradores' },
    { pattern: '/planner/month', label: 'Calendario', parent: '/colaboradores' },
    { pattern: '/employees/reports', label: 'Informes RH', parent: '/colaboradores' },

    { pattern: '/company/settings', label: 'Mi empresa', parent: '/empresa' },
    { pattern: '/company/branches', label: 'Sedes', parent: '/empresa' },
    { pattern: '/company/preferences', label: 'Preferencias', parent: '/empresa' },

    { pattern: '/company/tables', label: 'Mesas físicas', parent: '/operacion' },
    { pattern: '/hours', label: 'Horarios', parent: '/operacion' },
    { pattern: '/company/printers', label: 'Impresoras', parent: '/operacion' },
    { pattern: '/company/kds', label: 'KDS / Cocina', parent: '/operacion' },

    { pattern: '/company/dian', label: 'Facturación DIAN', parent: null },
    { pattern: '/company/whatsapp', label: 'WhatsApp', parent: '/canales' },

    { pattern: '/billing', label: 'Facturación', parent: null },
    { pattern: '/chats', label: 'Chats', parent: null },
    { pattern: '/me', label: 'Mi perfil', parent: null },
    { pattern: '/me/profile', label: 'Perfil', parent: '/me' },
    { pattern: '/settings/notifications', label: 'Notificaciones', parent: null },

    { pattern: '/onboarding', label: 'Onboarding', parent: null },
    { pattern: '/onboarding/:step', label: null, parent: '/onboarding' },
];

/**
 * Patterns "virtuales" para grupos del sidebar que NO son rutas reales
 * pero aparecen como crumbs intermedios (sin link a sí mismos).
 */
export const BREADCRUMB_GROUPS: Record<string, string> = {
    '/contactos': 'Contactos',
    '/inventario': 'Inventario',
    '/analisis': 'Análisis',
    '/identidades': 'Identidades',
    '/colaboradores': 'Colaboradores',
    '/empresa': 'Empresa',
    '/operacion': 'Operación',
    '/canales': 'Canales',
};

export interface BreadcrumbItem {
    label: string;
    href: string | null;
}

function matchPattern(pathname: string, pattern: string): boolean {
    const patternSegments = pattern.split('/').filter(Boolean);
    const pathSegments = pathname.split('/').filter(Boolean);
    if (patternSegments.length !== pathSegments.length) return false;
    return patternSegments.every((seg, i) => seg.startsWith(':') || seg === pathSegments[i]);
}

function findRoute(pathname: string): BreadcrumbRoute | null {
    return BREADCRUMB_ROUTES.find((r) => matchPattern(pathname, r.pattern)) ?? null;
}

/**
 * Construye la cadena de breadcrumb desde el root Dashboard hasta la
 * página actual, derivando los padres declarados en BREADCRUMB_ROUTES.
 *
 * @param pathname URL actual sin query/hash
 * @param leafLabel Label del último crumb cuando el pattern tiene
 *   segmento dinámico (`:id`) — viene del title del PageShell
 */
export function buildBreadcrumb(pathname: string, leafLabel?: string): BreadcrumbItem[] {
    const route = findRoute(pathname);
    if (!route) return [];

    const chain: BreadcrumbItem[] = [];

    const leaf: BreadcrumbItem = {
        label: route.label ?? leafLabel ?? '…',
        href: null,
    };

    // Resolver parents
    let currentParent = route.parent;
    while (currentParent) {
        if (BREADCRUMB_GROUPS[currentParent]) {
            // Grupo virtual del sidebar — sin href clickeable
            chain.unshift({ label: BREADCRUMB_GROUPS[currentParent], href: null });
            currentParent = null; // Los grupos van directo bajo Dashboard
        } else {
            const parentRoute = BREADCRUMB_ROUTES.find((r) => r.pattern === currentParent);
            if (!parentRoute) break;
            chain.unshift({
                label: parentRoute.label ?? '…',
                href: parentRoute.pattern.includes(':') ? null : parentRoute.pattern,
            });
            currentParent = parentRoute.parent;
        }
    }

    // Root: siempre "Dashboard" como primer crumb si la página no ES el dashboard
    if (route.pattern !== '/dashboard') {
        chain.unshift({ label: 'Dashboard', href: '/dashboard' });
    }

    chain.push(leaf);
    return chain;
}
