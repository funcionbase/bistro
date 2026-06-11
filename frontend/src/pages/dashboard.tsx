import { AlertsFeed } from '@/components/alerts/alerts-feed';
import { ResolutionExpirationAlert } from '@/components/dian/resolution-expiration-alert';
import { AppLink } from '@/components/app-link';
import LiveIndicator from '@/components/dashboard/live-indicator';
import PeriodFilter from '@/components/dashboard/period-filter';
import DeliveriesSkeleton from '@/components/dashboard/skeleton/deliveries-skeleton';
import MetricCardSkeleton from '@/components/dashboard/skeleton/metric-card-skeleton';
import AbandonmentPanel from '@/components/metrics/abandonment-panel';
import ActiveOrdersPanel from '@/components/metrics/active-orders-panel';
import HeatmapPanel from '@/components/metrics/heatmap-panel';
import KpiCard from '@/components/metrics/kpi-card';
import { PageShell } from '@/components/page-shell';
import InstallPwaPrompt from '@/components/pwa/install-pwa-prompt';
import IosInstallHint from '@/components/pwa/ios-install-hint';
import UpdateAvailableToast from '@/components/pwa/update-available-toast';
import BranchFilterTabs from '@/components/reports/branch-filter-tabs';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { LivePollingToggle } from '@/components/ui/live-polling-toggle';
import { PageHeader } from '@/components/ui/page-header';
import { StatTile } from '@/components/ui/stat-tile';
import { useLivePolling } from '@/hooks/use-live-polling';
import { usePeriodFilter } from '@/hooks/use-period-filter';
import { usePermissions } from '@/hooks/use-permissions';
import { useWidgetFetch } from '@/hooks/use-widget-fetch';
import { apiClient } from '@/lib/api-client';
import { isFullyBlocked, isPendingVerification } from '@/lib/company-status';
import { useSharedData } from '@/lib/shared-data';
import { type DashboardData, type MetricActiveOrders, type Period } from '@/types';
import { useQuery } from '@tanstack/react-query';
import { Activity, AlertCircle, AlertTriangle, DollarSign, Package, ShoppingBag, TrendingDown, Truck } from 'lucide-react';
import { useMemo } from 'react';


const OVERDUE_THRESHOLD_MIN = 45;

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);
}

function formatDuration(minutes: number | null): string {
    if (minutes === null) return '—';
    return `${Math.round(minutes)} min`;
}

export default function Dashboard() {
    const searchParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
    const companyStatus = searchParams.get('company_status') ?? undefined;
    const initialPeriod = (searchParams.get('period') ?? 'today') as Period;

    const { period, setPeriod, branchFilter, setBranchFilter } = usePeriodFilter(initialPeriod);
    const { has: hasPermission } = usePermissions();
    // Sin sede activa, los endpoints `/api/v1/metrics/orders/active`,
    // `/cash-register/current` y compañía devuelven 422 NO_ACTIVE_BRANCH.
    // Gateamos el polling para no spammear logs/red mientras el usuario
    // resuelve la sede vía BranchSwitcher o /auth/branch-selector.
    //
    // Cuando la empresa está `suspended` (#193), `EnsureCompanyNotBlocked`
    // devuelve 403 `company_payment_blocked` en cualquier endpoint operativo.
    const { activeBranch, activeCompany } = useSharedData();
    const hasActiveBranch = Boolean(activeBranch?.id);
    const isCompanySuspended = activeCompany ? isFullyBlocked(activeCompany.status) : false;
    const canPollMetrics = hasActiveBranch && !isCompanySuspended;

    // Datos del dashboard (#220): un solo endpoint que reemplaza las props
    // diferidas de Inertia. Re-fetch automático cada 5 min y al cambiar
    // período/sede (la queryKey los incluye).
    const dashboardQuery = useQuery<DashboardData>({
        queryKey: ['dashboard', period, branchFilter],
        queryFn: ({ signal }) =>
            apiClient.get<DashboardData>('/api/v1/dashboard', {
                params: { period, ...(branchFilter !== 'active' ? { branch: branchFilter } : {}) },
                signal,
            }),
        refetchInterval: canPollMetrics ? 300_000 : false,
    });

    const data = dashboardQuery.data;
    const summary = data?.summary;
    const heatmap = data?.heatmap;
    const abandonment = data?.abandonment;
    const deliveries = data?.deliveries;
    const lowStockInventory = data?.lowStockInventory;
    const needsProfileCompletion = data?.needsProfileCompletion ?? false;
    const periodLoading = dashboardQuery.isFetching;
    const lastUpdated = dashboardQuery.dataUpdatedAt ? new Date(dashboardQuery.dataUpdatedAt) : undefined;

    // Toggle de refresco en vivo a 30s (intervalo canónico del frontend).
    const dashboardLive = useLivePolling({
        intervalMs: 30_000,
        onTick: () => {
            void dashboardQuery.refetch();
        },
    });

    // Órdenes activas — polling client-side cada 30s (canónico).
    // Deshabilitado si no hay sede activa (422 NO_ACTIVE_BRANCH recurrente
    // por EnsureBranchAccess) o si la empresa está suspended (403
    // company_payment_blocked recurrente por EnsureCompanyNotBlocked).
    const {
        data: activeOrders,
        loading: activeOrdersLoading,
        error: activeOrdersError,
        retry: retryActiveOrders,
    } = useWidgetFetch<MetricActiveOrders>({
        url: '/api/v1/metrics/orders/active',
        interval: 30_000,
        enabled: canPollMetrics,
    });

    const summaryLoading = summary === undefined;
    const heatmapLoading = heatmap === undefined;
    const abandonmentLoading = abandonment === undefined;
    const deliveriesLoading = deliveries === undefined;

    const comparison = summary?.comparison ?? null;
    // Si el período anterior es 0, no hay base válida para un % de cambio (dividir por 1
    // produce porcentajes de cinco dígitos engañosos). Devolvemos null para que el
    // KpiCard oculte el delta en lugar de mostrar "+1000%".
    const ordersChange =
        comparison && comparison.total_orders > 0 ? ((summary!.total_orders - comparison.total_orders) / comparison.total_orders) * 100 : null;
    const revenueChange =
        comparison && comparison.total_revenue > 0 ? ((summary!.total_revenue - comparison.total_revenue) / comparison.total_revenue) * 100 : null;
    const changeLabel = comparison?.period_label ? `vs ${comparison.period_label}` : undefined;

    const deliveryAggregates = useMemo(() => {
        if (!deliveries || deliveries.length === 0) return null;
        const total = deliveries.reduce((s, d) => s + d.total_deliveries, 0);
        const completed = deliveries.reduce((s, d) => s + d.completed, 0);
        const cancelled = deliveries.reduce((s, d) => s + d.cancelled, 0);
        const inProgress = Math.max(0, total - completed - cancelled);
        const durRows = deliveries.filter((d) => d.average_duration_minutes !== null);
        const avgDuration = durRows.length > 0 ? durRows.reduce((s, d) => s + (d.average_duration_minutes ?? 0), 0) / durRows.length : null;
        const alertOverdue = durRows.some((d) => (d.average_duration_minutes ?? 0) > OVERDUE_THRESHOLD_MIN);
        return { total, completed, cancelled, inProgress, avgDuration, alertOverdue };
    }, [deliveries]);

    return (
        <PageShell title="Dashboard">
            <UpdateAvailableToast />
            <InstallPwaPrompt />
            <IosInstallHint />

            <div className="space-y-8 p-4 sm:p-6 md:space-y-10 md:p-8">
                {needsProfileCompletion && (
                    <Alert variant="default">
                        <AlertTitle>Completa tu perfil</AlertTitle>
                        <AlertDescription>
                            Para acceder a todas las funcionalidades, completa tus datos personales.{' '}
                            <a href="/enrollment/user" className="text-primary ml-1 underline">
                                Ir a completar perfil
                            </a>
                        </AlertDescription>
                    </Alert>
                )}

                {companyStatus !== undefined && isPendingVerification(companyStatus) && (
                    <Alert variant="warning">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Empresa pendiente de activación</AlertTitle>
                        <AlertDescription>
                            Tu empresa está siendo revisada por nuestro equipo. Recibirás una notificación cuando esté activa y puedas comenzar a
                            operar.
                        </AlertDescription>
                    </Alert>
                )}

                {/* Alertas accionables (#124). Gate por reports.read — el feed
                    expone información de márgenes/costos indirectamente. */}
                {hasPermission('reports.read') && <AlertsFeed />}

                {/* HU #235 — banner de resolución DIAN por vencer/agotada. */}
                {hasPermission('dian.config.read') && <ResolutionExpirationAlert />}

                {lowStockInventory && lowStockInventory.count > 0 && (
                    <Alert variant="warning">
                        <Package className="h-4 w-4" />
                        <AlertTitle className="font-semibold">
                            {lowStockInventory.count === 1
                                ? '1 insumo por debajo del mínimo'
                                : `${lowStockInventory.count} insumos por debajo del mínimo`}
                        </AlertTitle>
                        <AlertDescription>
                            <div className="space-y-1">
                                <ul className="list-disc pl-5 text-sm">
                                    {lowStockInventory.items.map((it) => (
                                        <li key={it.id}>
                                            <span className="font-medium">{it.name}</span> — {it.current_stock} {it.unit}{' '}
                                            <span className="opacity-70">
                                                (mín. {it.min_stock} {it.unit})
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <AppLink href="/inventory?low_stock=1" className="inline-block text-sm font-medium underline underline-offset-2">
                                    Ver inventario →
                                </AppLink>
                            </div>
                        </AlertDescription>
                    </Alert>
                )}

                {activeOrdersError && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription className="flex items-center gap-3">
                            Conexión perdida — algunos datos pueden no estar actualizados.
                            <button onClick={retryActiveOrders} className="underline">
                                Reintentar
                            </button>
                        </AlertDescription>
                    </Alert>
                )}

                <PageHeader
                    eyebrow="Resumen"
                    title="Dashboard"
                    description="Estado operativo del período seleccionado."
                    variant="editorial"
                    actions={
                        <>
                            <PeriodFilter value={period} onChange={setPeriod} />
                            <LivePollingToggle {...dashboardLive} onToggle={dashboardLive.toggle} />
                            <LiveIndicator timestamp={lastUpdated} isLive={!summaryLoading && !periodLoading} />
                        </>
                    }
                />

                <BranchFilterTabs value={branchFilter} onChange={setBranchFilter} />

                {/* KPI Cards — 1 col mobile / 2 tablet / 4 desktop */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {summaryLoading ? (
                        <>
                            <MetricCardSkeleton />
                            <MetricCardSkeleton />
                            <MetricCardSkeleton />
                            <MetricCardSkeleton />
                        </>
                    ) : (
                        <>
                            <KpiCard
                                label="Órdenes"
                                value={summary?.total_orders ?? 0}
                                sub={`${summary?.completed_orders ?? 0} completadas`}
                                icon={ShoppingBag}
                                changePercent={ordersChange}
                                changeLabel={changeLabel}
                            />
                            <KpiCard
                                label="Ingresos"
                                value={summary ? formatCurrency(summary.total_revenue) : '—'}
                                sub={`Ticket promedio ${summary ? formatCurrency(summary.average_ticket) : '—'}`}
                                icon={DollarSign}
                                accent
                                changePercent={revenueChange}
                                changeLabel={changeLabel}
                            />
                            <KpiCard label="Órdenes activas" value={summary?.orders_in_progress ?? 0} sub="en curso ahora" icon={Activity} />
                            <KpiCard
                                label="Canceladas / Abandonadas"
                                value={`${summary?.cancelled_orders ?? 0} / ${summary?.abandoned_carts ?? 0}`}
                                sub="en el período"
                                icon={TrendingDown}
                            />
                        </>
                    )}
                </div>

                {/* Paneles — 1 col mobile / 2 cols desktop */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <ActiveOrdersPanel data={activeOrders} loading={activeOrdersLoading} />
                    <AbandonmentPanel data={abandonment ?? null} loading={abandonmentLoading || periodLoading} formatCurrency={formatCurrency} />
                </div>

                {/* Heatmap horario — full-width */}
                <div className={`transition-opacity duration-300 ${heatmapLoading || periodLoading ? 'opacity-60' : 'opacity-100'}`}>
                    <HeatmapPanel data={heatmap ?? null} loading={heatmapLoading || periodLoading} />
                </div>

                {/* Panel de entregas */}
                {deliveriesLoading ? (
                    <DeliveriesSkeleton />
                ) : deliveries !== null ? (
                    <DashboardPanel
                        title="Entregas del período"
                        icon={Truck}
                        rightSlot={
                            deliveryAggregates?.alertOverdue ? (
                                <Badge variant="warning" className="gap-1">
                                    <AlertTriangle className="h-3 w-3" />
                                    Demora detectada
                                </Badge>
                            ) : undefined
                        }
                    >
                        {!deliveryAggregates ? (
                            <p className="text-muted-foreground text-sm">Sin entregas registradas en el período.</p>
                        ) : (
                            <>
                                <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <StatTile value={deliveryAggregates.inProgress} label="En progreso" tone="default" />
                                    <StatTile value={deliveryAggregates.completed} label="Completadas" tone="safe" />
                                    <StatTile value={formatDuration(deliveryAggregates.avgDuration)} label="Tiempo prom." tone="default" />
                                </div>

                                {deliveries.length > 0 && (
                                    <div className="space-y-1.5">
                                        {deliveries.map((courier) => (
                                            <div
                                                key={courier.user_id}
                                                className="bg-muted flex items-center justify-between rounded-lg px-3 py-2 text-sm"
                                            >
                                                <span className="font-medium">{courier.courier_name}</span>
                                                <div className="text-muted-foreground flex items-center gap-3 text-xs tabular-nums">
                                                    <span>{courier.completed} compl.</span>
                                                    <span>{courier.success_rate}</span>
                                                    {courier.average_duration_minutes !== null && (
                                                        <span
                                                            className={
                                                                courier.average_duration_minutes > OVERDUE_THRESHOLD_MIN
                                                                    ? 'font-medium text-[color:var(--color-status-warning)]'
                                                                    : ''
                                                            }
                                                        >
                                                            {formatDuration(courier.average_duration_minutes)}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                    </DashboardPanel>
                ) : null}

                <div className="text-center">
                    <AppLink href="/company/metrics" className="text-muted-foreground hover:text-foreground text-sm underline underline-offset-4">
                        Ver análisis completo por período →
                    </AppLink>
                </div>
            </div>
        </PageShell>
    );
}
