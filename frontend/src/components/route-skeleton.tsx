import { Skeleton } from '@/components/ui/skeleton';
import { BillingInvoicesSkeleton, BillingSubscriptionSkeleton } from '@/components/ui/billing-skeleton';
import { CajaTableSessionSkeleton } from '@/components/ui/caja-table-session-skeleton';
import { CashierSkeleton } from '@/components/ui/cashier-skeleton';
import { ChatsSkeleton } from '@/components/ui/chats-skeleton';
import { ClientDetailSkeleton } from '@/components/ui/client-detail-skeleton';
import { ClientsListSkeleton } from '@/components/ui/clients-list-skeleton';
import { CouponDetailSkeleton } from '@/components/ui/coupon-detail-skeleton';
import { CouponsListSkeleton } from '@/components/ui/coupons-list-skeleton';
import { DeliveryMetricsSkeleton } from '@/components/ui/delivery-metrics-skeleton';
import { EmployeeDetailSkeleton } from '@/components/ui/employee-detail-skeleton';
import { EmployeesTableSkeleton } from '@/components/ui/employees-table-skeleton';
import { HoursSkeleton } from '@/components/ui/hours-skeleton';
import { InventorySkeleton } from '@/components/ui/inventory-skeleton';
import { KanbanBoardSkeleton } from '@/components/ui/kanban-board-skeleton';
import { KdsSkeleton } from '@/components/ui/kds-skeleton';
import { LoyaltyReportsSkeleton } from '@/components/ui/loyalty-reports-skeleton';
import { MenuDetailSkeleton } from '@/components/ui/menu-detail-skeleton';
import { MenusListSkeleton } from '@/components/ui/menus-list-skeleton';
import { MyDeliveriesSkeleton } from '@/components/ui/my-deliveries-skeleton';
import { PurchasesSkeleton } from '@/components/ui/purchases-skeleton';
import { ReportsTableSkeleton } from '@/components/ui/reports-table-skeleton';
import { RolesTableSkeleton } from '@/components/ui/roles-table-skeleton';
import { SettingsFormSkeleton } from '@/components/ui/settings-form-skeleton';
import { SuppliersListSkeleton } from '@/components/ui/suppliers-list-skeleton';
import { TableSessionDetailSkeleton } from '@/components/ui/table-session-detail-skeleton';
import { TableSessionsListSkeleton } from '@/components/ui/table-sessions-list-skeleton';
import { TablesGridSkeleton } from '@/components/ui/tables-grid-skeleton';
import { UsersTableSkeleton } from '@/components/ui/users-table-skeleton';
import { WeekAgendaSkeleton } from '@/components/ui/week-agenda-skeleton';
import { WhatsappPageSkeleton } from '@/components/ui/whatsapp-page-skeleton';
import { MetricsSkeleton } from '@/components/ui/metrics-skeleton';
import type { ReactNode } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Fallback de `<Suspense>` consciente de la ruta (#269, Fase 1 + 2).
 *
 * Mientras el chunk lazy de la ruta destino se descarga, en vez de un spinner
 * centrado genérico se pinta el skeleton que **calca el layout** de esa
 * pantalla — reutilizando los `*-skeleton.tsx` ya existentes. Las rutas sin
 * skeleton dedicado caen al `PageShellSkeleton` genérico (header + panel),
 * nunca a pantalla en blanco ni a spinner suelto.
 *
 * IMPORTANTE: este módulo se importa de forma **eager** desde el layout, por
 * lo que sus skeletons viven en el bundle principal. Es intencional — el
 * skeleton debe pintarse de inmediato; si fuera lazy no podría mostrarse al
 * instante. Los skeletons son markup barato (solo `<Skeleton>` + tokens DS).
 *
 * Convención (ver `FRONTEND_UI_GUIDELINES.md` §13): cada ruta pesada expone un
 * `*-skeleton.tsx` que calca su layout y se enchufa aquí en `ROUTE_SKELETONS`.
 */

/** Padding canónico del área de contenido (igual al de las páginas reales). */
function Padded({ children }: { children: ReactNode }) {
    return <div className="p-4 sm:p-6">{children}</div>;
}

function BillingPageSkeleton() {
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <BillingSubscriptionSkeleton />
            <BillingInvoicesSkeleton />
        </div>
    );
}

/**
 * Skeleton genérico de "shell de página": silueta del PageHeader + un panel de
 * contenido. Respaldo para rutas sin skeleton dedicado.
 */
function PageShellSkeleton() {
    return (
        <div aria-busy="true" aria-label="Cargando" className="space-y-6 p-4 sm:p-6">
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-24 rounded-full" />
                    <Skeleton className="h-9 w-56" />
                    <Skeleton className="h-4 w-full max-w-xl" />
                </div>
                <div className="flex flex-wrap gap-2">
                    <Skeleton className="h-9 w-28 rounded-md" />
                    <Skeleton className="h-9 w-28 rounded-md" />
                </div>
            </div>
            {/* Panel de contenido */}
            <div className="border-border bg-card space-y-4 rounded-2xl border p-5 shadow-sm">
                <Skeleton className="h-5 w-40" />
                <div className="space-y-3">
                    {Array.from({ length: 6 }).map((_, i) => (
                        <Skeleton key={i} className="h-12 w-full rounded-lg" />
                    ))}
                </div>
            </div>
        </div>
    );
}

interface SkeletonRoute {
    /** Predicado sobre el pathname normalizado (sin trailing slash). */
    match: (path: string) => boolean;
    render: () => ReactNode;
}

/**
 * Mapa ruta → skeleton. Orden por especificidad: las rutas de detalle
 * (con parámetro) deben ir antes que su listado para no ser tapadas.
 */
const ROUTE_SKELETONS: SkeletonRoute[] = [
    // Órdenes — el kanban gestiona su propio padding interno (no envolver).
    { match: (p) => p === '/orders/board', render: () => <KanbanBoardSkeleton /> },
    { match: (p) => p === '/orders/tables', render: () => <Padded><TablesGridSkeleton /></Padded> },
    { match: (p) => /^\/orders\/table-sessions\/[^/]+$/.test(p), render: () => <Padded><TableSessionDetailSkeleton /></Padded> },
    { match: (p) => p === '/orders/table-sessions', render: () => <Padded><TableSessionsListSkeleton /></Padded> },
    { match: (p) => p === '/orders/cashier', render: () => <Padded><CashierSkeleton /></Padded> },
    { match: (p) => /^\/caja\/table-sessions\/[^/]+$/.test(p), render: () => <Padded><CajaTableSessionSkeleton /></Padded> },

    // KDS
    { match: (p) => p === '/kds', render: () => <Padded><KdsSkeleton /></Padded> },

    // Inventario / compras / proveedores
    { match: (p) => p === '/inventory', render: () => <Padded><InventorySkeleton /></Padded> },
    { match: (p) => p === '/purchases', render: () => <Padded><PurchasesSkeleton /></Padded> },
    { match: (p) => p === '/suppliers', render: () => <Padded><SuppliersListSkeleton /></Padded> },

    // Reportes / métricas / fidelización / domicilios
    { match: (p) => p === '/company/reports', render: () => <Padded><ReportsTableSkeleton /></Padded> },
    { match: (p) => p === '/company/metrics', render: () => <Padded><MetricsSkeleton /></Padded> },
    { match: (p) => p === '/loyalty/reports', render: () => <Padded><LoyaltyReportsSkeleton /></Padded> },
    { match: (p) => p === '/deliveries/metrics', render: () => <Padded><DeliveryMetricsSkeleton /></Padded> },
    { match: (p) => p === '/my-deliveries', render: () => <Padded><MyDeliveriesSkeleton /></Padded> },

    // Facturación SaaS (suscripción + facturas).
    { match: (p) => p === '/billing', render: () => <BillingPageSkeleton /> },

    // Clientes
    { match: (p) => /^\/clients\/[^/]+$/.test(p), render: () => <Padded><ClientDetailSkeleton /></Padded> },
    { match: (p) => p === '/clients', render: () => <Padded><ClientsListSkeleton /></Padded> },

    // Cupones
    { match: (p) => /^\/coupons\/[^/]+$/.test(p), render: () => <Padded><CouponDetailSkeleton /></Padded> },
    { match: (p) => p === '/coupons', render: () => <Padded><CouponsListSkeleton /></Padded> },

    // Menú
    { match: (p) => /^\/menu\/[^/]+$/.test(p), render: () => <Padded><MenuDetailSkeleton /></Padded> },
    { match: (p) => p === '/menu', render: () => <Padded><MenusListSkeleton /></Padded> },

    // Chats
    { match: (p) => p === '/chats', render: () => <Padded><ChatsSkeleton /></Padded> },

    // Empleados — `new` (form) y `reports` antes que el detalle `:id`.
    { match: (p) => p === '/employees', render: () => <Padded><EmployeesTableSkeleton /></Padded> },
    { match: (p) => p === '/employees/new', render: () => <Padded><SettingsFormSkeleton fields={6} /></Padded> },
    { match: (p) => p !== '/employees/reports' && /^\/employees\/[^/]+$/.test(p), render: () => <Padded><EmployeeDetailSkeleton /></Padded> },

    // Identidades
    { match: (p) => p === '/identities/roles', render: () => <Padded><RolesTableSkeleton /></Padded> },
    { match: (p) => p === '/identities/users', render: () => <Padded><UsersTableSkeleton /></Padded> },

    // Horarios / agenda personal
    { match: (p) => p === '/hours', render: () => <Padded><HoursSkeleton /></Padded> },
    { match: (p) => p === '/me/agenda', render: () => <Padded><WeekAgendaSkeleton /></Padded> },

    // WhatsApp + formularios tipo settings
    { match: (p) => p === '/company/whatsapp', render: () => <Padded><WhatsappPageSkeleton /></Padded> },
    {
        match: (p) => p === '/company/settings' || p === '/company/preferences' || p === '/company/dian',
        render: () => <Padded><SettingsFormSkeleton /></Padded>,
    },
    { match: (p) => p.startsWith('/settings/'), render: () => <Padded><SettingsFormSkeleton /></Padded> },
];

export function RouteSkeleton() {
    const { pathname } = useLocation();
    const normalized = pathname.replace(/\/+$/, '') || '/';
    const entry = ROUTE_SKELETONS.find((route) => route.match(normalized));
    return <>{entry ? entry.render() : <PageShellSkeleton />}</>;
}
