import { BlockedCompanySwitchBanner } from '@/components/blocked-company-switch-banner';
import { BranchSwitcher } from '@/components/branch-switcher';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { RestaurantIdentity } from '@/components/restaurant-identity';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader } from '@/components/ui/sidebar';
import { useChatNotifications } from '@/hooks/use-chat-notifications';
import { isFullyBlocked } from '@/lib/company-status';
import { hasNoBranchAssigned } from '@/lib/branch-access';
import { isCourierOnlyMode } from '@/lib/courier-mode';
import { route } from '@/lib/route-compat';
import { useSharedData } from '@/lib/shared-data';
import { type NavItem } from '@/types';
import {
    BarChart2,
    Bike,
    Building2,
    CalendarRange,
    ChefHat,
    ClipboardList,
    Clock,
    FileText,
    Contact as ContactIcon,
    KanbanSquare,
    LayoutGrid,
    LineChart,
    MapPin,
    MessageCircle,
    Package,
    Phone,
    Printer,
    Receipt,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    Sparkles,
    Table2,
    Tag,
    Truck,
    UserCog,
    Users,
    UtensilsCrossed,
    Warehouse,
} from 'lucide-react';

/**
 * Sidebar organizado en 5 secciones, priorizando acciones de venta del
 * día a día arriba para que un cajero/mesero llegue con un solo click.
 *
 *  1. **Día a día** — Dashboard, Caja, Mesas, Tablero, Cocina (KDS),
 *     Ventas del día y Mis entregas. Items planos para acceso directo sin
 *     clicks extra. Orden por frecuencia real de uso operativo.
 *  2. **Catálogo y CRM** — Menú (plano) y Contactos (submenu con
 *     Clientes / Cupones / Fidelización).
 *  3. **Operaciones** — Inventario (Existencias, Compras, Proveedores, Bodegas)
 *     y Análisis (Métricas, Informes, Métricas de domicilios, Documentos DIAN).
 *     Frecuencia media, típicamente managers.
 *  4. **Equipo** — Identidades (Usuarios, Roles) y Colaboradores
 *     (Empleados, Planificador, Calendario, Informes RH).
 *  5. **Administración** — Antes 9 items planos, ahora agrupados:
 *     - **Empresa** (submenu): Mi empresa, Sedes, Preferencias.
 *     - **Operación** (submenu): Mesas físicas, Horarios, Impresoras, KDS.
 *     - **Facturación DIAN** (plano): item directo por su importancia.
 *     - **Canales** (submenu): WhatsApp (Instagram/Facebook próx.).
 *
 * Items con `permission` se ocultan según RBAC (ver NavMain.tsx). Un grupo
 * se colapsa cuando todos sus hijos están ocultos por permisos, así que un
 * cajero típico verá únicamente "Día a día" y los items para los que tenga
 * acceso — reduciendo ruido y enfoque.
 *
 * Renames recientes para evitar ambigüedad:
 *  - "Calendario mensual" → "Calendario".
 *  - 2do "Informes" (en Colaboradores) → "Informes RH" (Análisis tiene
 *    "Informes" para ventas).
 *  - "Listado" (Contactos/Colaboradores) → "Clientes"/"Empleados" para que
 *    coincidan con la URL (/clients, /employees).
 *  - "Domicilios (KPIs)" → "Métricas de domicilios" (alineado con
 *    /deliveries/metrics).
 *  - "Cocina" → "Cocina (KDS)" para explicitar la sigla técnica de /kds.
 *
 * Para extender: ubicar el item en la sección que más se le parezca según
 * frecuencia de uso, no por afinidad técnica.
 */
export function AppSidebar() {
    // Cuando la empresa está suspendida por mora, el sidebar se reduce
    // a Dashboard + Mi empresa. El resto se oculta. El middleware backend
    // (`EnsureCompanyNotBlocked`) ya gatea las URLs operativas; este filtro
    // visual evita que el operador vea opciones que llevan a un redirect.
    // El estado past_due NO se filtra: el cliente sigue operando normal,
    // solo ve el banner blando con countdown.
    const { activeCompany, permissions = [], role, branches } = useSharedData();
    const isSuspended = activeCompany ? isFullyBlocked(activeCompany.status) : false;
    // Rol Domiciliario u otro courier-only — sidebar reducido a
    // "Mis entregas" + Mi perfil (en footer). Decisión por permisos, no
    // por nombre del rol, para sobrevivir renombres.
    const isCourierOnly = isCourierOnlyMode(permissions, role?.is_system === true);
    // Usuario sin sede asignada: rol no-sistema con cero sedes accesibles. El
    // sidebar se reduce a "Dashboard" (que muestra el mensaje de "pedí una
    // sede a tu gerente/dueño"). Sin sede no puede operar nada branch-scoped.
    const noBranch = !isSuspended && !!role && hasNoBranchAssigned(role.is_system === true, (branches ?? []).length);

    const dayToDayItems: NavItem[] = [
        {
            title: 'Dashboard',
            url: route('dashboard'),
            icon: LayoutGrid,
        },
        {
            title: 'Caja',
            url: route('orders.cashier'),
            icon: Receipt,
            permission: 'orders.create',
        },
        {
            title: 'Mesas',
            url: route('orders.tables'),
            icon: Table2,
            permission: 'orders.read',
            businessCapability: 'tables',
        },
        {
            title: 'Tablero',
            url: route('orders.board'),
            icon: KanbanSquare,
            permission: 'orders.read',
        },
        {
            // El badge de conversaciones sin responder se inyecta más abajo
            // (§8.4b punto 1): quien atiende el WhatsApp necesita verlo desde
            // cualquier pantalla, no solo dentro de la bandeja.
            title: 'Chats',
            url: route('chats'),
            icon: MessageCircle,
            permission: 'chats.read',
        },
        {
            // KDS por estación. Item visible para cualquier rol con
            // kds.read=true. Owner bypass automático vía is_system.
            // Para courier-only se oculta porque su rol no incluye este permiso.
            title: 'Cocina (KDS)',
            url: route('kds'),
            icon: ChefHat,
            permission: 'kds.read',
            businessCapability: 'kds',
        },
        {
            title: 'Ventas del día',
            url: route('orders.deliveries'),
            icon: Truck,
            permission: 'deliveries.read',
            businessCapability: 'delivery',
        },
        // Vista mobile-first del domiciliario. Visible para cualquier
        // rol con `deliveries.read` — el filtro `user_id = actor` del
        // backend asegura que solo se vean entregas propias. El owner que
        // entra ve empty state si no tiene asignaciones, no un error.
        {
            title: 'Mis entregas',
            url: route('deliveries.mine'),
            icon: Bike,
            permission: 'deliveries.read',
            businessCapability: 'delivery',
        },
    ];

    const catalogItems: NavItem[] = [
        {
            title: 'Menú',
            url: route('menu'),
            icon: UtensilsCrossed,
            permission: 'menu.read',
        },
        {
            title: 'Contactos',
            icon: ContactIcon,
            children: [
                {
                    title: 'Clientes',
                    url: route('clients'),
                    icon: ContactIcon,
                    permission: 'clients.read',
                },
                {
                    title: 'Cupones',
                    url: route('coupons'),
                    icon: Tag,
                    permission: 'coupons.read',
                },
                {
                    title: 'Fidelización',
                    url: route('loyalty.reports'),
                    icon: Sparkles,
                    permission: 'loyalty.read',
                },
            ],
        },
    ];

    const operationsItems: NavItem[] = [
        {
            title: 'Inventario',
            icon: Package,
            businessCapability: 'inventory',
            children: [
                {
                    title: 'Existencias',
                    url: route('inventory'),
                    icon: Package,
                    permission: 'inventory.read',
                    businessCapability: 'inventory',
                },
                {
                    // Página unificada: órdenes de compra + catálogo de
                    // proveedores en tabs (/purchases?tab=proveedores).
                    title: 'Compras y proveedores',
                    url: route('purchases'),
                    icon: ShoppingCart,
                    anyPermission: ['purchases.read', 'suppliers.read'],
                    businessCapability: 'inventory',
                },
                {
                    title: 'Bodegas',
                    url: route('company.warehouses'),
                    icon: Warehouse,
                    permission: 'warehouses.manage',
                    businessCapability: 'inventory',
                },
            ],
        },
        {
            title: 'Análisis',
            icon: BarChart2,
            children: [
                {
                    title: 'Métricas',
                    url: route('company.metrics'),
                    icon: LineChart,
                    permission: 'reports.read',
                },
                {
                    title: 'Informes',
                    url: route('company.reports'),
                    icon: BarChart2,
                    permission: 'reports.read',
                },
                {
                    title: 'Métricas de domicilios',
                    url: route('deliveries.metrics'),
                    icon: ClipboardList,
                    permission: 'deliveries.read',
                    businessCapability: 'delivery',
                },
                {
                    title: 'Documentos DIAN',
                    url: route('dian.documents'),
                    icon: FileText,
                    permission: 'dian.documents.read',
                },
            ],
        },
    ];

    const teamItems: NavItem[] = [
        {
            title: 'Identidades',
            icon: Users,
            children: [
                {
                    title: 'Usuarios',
                    url: route('identities.users'),
                    icon: Users,
                    permission: 'users.read',
                },
                {
                    title: 'Roles',
                    url: route('identities.roles'),
                    icon: ShieldCheck,
                    permission: 'roles.read',
                },
            ],
        },
        {
            title: 'Colaboradores',
            icon: UserCog,
            children: [
                {
                    title: 'Empleados',
                    url: route('employees.index'),
                    icon: UserCog,
                    permission: 'employees.read',
                },
                {
                    title: 'Planificador',
                    url: route('planner.week'),
                    icon: CalendarRange,
                    permission: 'shifts.read',
                },
                {
                    title: 'Informes RH',
                    url: route('employees.reports'),
                    icon: BarChart2,
                    permission: 'workforce.reports',
                },
            ],
        },
    ];

    // Administración agrupada en submenus para reducir ruido visual.
    // Antes eran 9 items planos; ahora 4 grupos lógicos (3 con submenu +
    // 1 directo). Conserva el principio de baja frecuencia (1 click más
    // está bien porque casi nadie entra acá durante la operación).
    //
    // Facturación DIAN se deja PLANA (sin submenu) porque, a diferencia
    // del resto de admin, se consulta al cierre del día y conviene tener
    // el acceso directo.
    const adminItems: NavItem[] = [
        {
            title: 'Empresa',
            icon: Building2,
            children: [
                {
                    title: 'Mi empresa',
                    url: route('company.settings'),
                    icon: Building2,
                    permission: 'company.update',
                },
                {
                    title: 'Sedes',
                    url: route('company.branches'),
                    icon: MapPin,
                    permission: 'branches.manage',
                },
                {
                    title: 'Preferencias',
                    url: route('company.preferences'),
                    icon: SlidersHorizontal,
                    permission: 'company.update',
                },
            ],
        },
        {
            title: 'Operación',
            icon: SlidersHorizontal,
            children: [
                {
                    title: 'Horarios',
                    url: route('hours'),
                    icon: Clock,
                    permission: 'hours.read',
                },
                {
                    title: 'Impresoras',
                    url: route('company.printers'),
                    icon: Printer,
                    permission: 'company.update',
                },
                {
                    // Configuración del KDS: estaciones, SLA y
                    // device-tokens. Permiso sensible de sede; owner por
                    // default, admin por delegación explícita (mismo
                    // patrón que cash_register.bypass_switch_lock).
                    title: 'KDS / Cocina',
                    url: route('company.kds'),
                    icon: ChefHat,
                    permission: 'kds_stations.read',
                    businessCapability: 'kds',
                },
            ],
        },
        {
            title: 'Facturación DIAN',
            url: route('company.dian'),
            icon: FileText,
            permission: 'dian.config.read',
        },
        {
            // Canales de mensajería (F3). Instagram/Facebook llegan después; por
            // eso es un grupo aunque hoy tenga un solo hijo.
            title: 'Canales',
            icon: Phone,
            children: [
                {
                    title: 'WhatsApp',
                    url: route('company.whatsapp'),
                    icon: MessageCircle,
                    permission: 'whatsapp.read',
                },
            ],
        },
        // Facturación (suscripción bistro): oculta del sidebar. La ruta
        // /billing sigue activa y se accede desde botones dentro de Mi
        // Empresa. Ver pages/company/settings.tsx para el call-to-action.
    ];

    // Filtro de mora: solo Dashboard + Mi empresa cuando la empresa está
    // suspended. Se computan sub-arrays para no romper el shape esperado por
    // `NavMain` y se omite el render de las secciones vacías.
    const visibleDayToDay =
        isSuspended || noBranch
            ? dayToDayItems.filter((item) => item.title === 'Dashboard')
            : isCourierOnly
              ? dayToDayItems.filter((item) => item.title === 'Mis entregas')
              : dayToDayItems;

    // Badge + título de pestaña de conversaciones sin responder (§8.4b punto 1).
    // Vive acá porque el sidebar está montado en todo el panel: el operador ve
    // el contador desde cualquier pantalla, que es cuando el aviso hace falta.
    // Se activa solo si el usuario puede leer chats — si no, ni se pollea.
    const canReadChats = role?.is_system === true || permissions.includes('chats.read');
    const { pending } = useChatNotifications(canReadChats && !isSuspended && !noBranch);

    const dayToDayWithBadges = pending > 0 ? visibleDayToDay.map((item) => (item.title === 'Chats' ? { ...item, badge: pending } : item)) : visibleDayToDay;
    // Empresa suspendida: solo permitimos ver el submenu "Empresa" (que
    // contiene "Mi empresa" — punto de entrada al cierre de billing).
    // Antes "Mi empresa" era item plano; ahora vive bajo "Empresa".
    const visibleAdmin = isSuspended ? adminItems.filter((item) => item.title === 'Empresa') : adminItems;

    // Courier-only oculta secciones que no le aplican. El switcher de
    // empresa/sede sigue arriba porque puede pertenecer a >1 sede.
    const showCatalogAndOps = !isSuspended && !isCourierOnly && !noBranch;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <RestaurantIdentity />
                <BranchSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <BlockedCompanySwitchBanner />
                <NavMain label="Día a día" items={dayToDayWithBadges} />
                {showCatalogAndOps && <NavMain label="Catálogo y clientes" items={catalogItems} />}
                {showCatalogAndOps && <NavMain label="Operaciones" items={operationsItems} />}
                {showCatalogAndOps && <NavMain label="Equipo" items={teamItems} />}
                {!isCourierOnly && !noBranch && <NavMain label="Administración" items={visibleAdmin} />}
            </SidebarContent>

            <SidebarFooter>
                {/* Manual de usuario y versiones fv/bv viven al final de /me. */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
