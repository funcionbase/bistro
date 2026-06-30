import HeatmapChart from '@/components/dashboard/heatmap-chart';
import LiveIndicator from '@/components/dashboard/live-indicator';
import AbandonmentPanel from '@/components/metrics/abandonment-panel';
import ActiveOrdersPanel from '@/components/metrics/active-orders-panel';
import DishMarginPanel from '@/components/metrics/dish-margin-panel';
import DishRankingPanel from '@/components/metrics/dish-ranking-panel';
import FoodCostPanel from '@/components/metrics/food-cost-panel';
import HeatmapPanel from '@/components/metrics/heatmap-panel';
import KpiCard from '@/components/metrics/kpi-card';
import MenuEngineeringPanel from '@/components/metrics/menu-engineering-panel';
import OfflineOperationPanel from '@/components/metrics/offline-operation-panel';
import { PageShell } from '@/components/page-shell';
import BranchFilterTabs from '@/components/reports/branch-filter-tabs';
import ExportPdfButton from '@/components/reports/export-pdf-button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { LivePollingToggle } from '@/components/ui/live-polling-toggle';
import { PageHeader } from '@/components/ui/page-header';
import { PeriodTabs } from '@/components/ui/period-tabs';
import { useLivePolling } from '@/hooks/use-live-polling';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import type {
    MetricActiveOrders,
    MetricCartAbandonment,
    MetricDishMargin,
    MetricHeatmap,
    MetricPeriod,
    MetricSummary,
    MetricTopItems,
    MetricWeeklyHeatmap,
} from '@/types';

import { Activity, DollarSign, ShoppingBag, TrendingDown } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';


const PERIOD_OPTIONS: ReadonlyArray<{ value: MetricPeriod; label: string }> = [
    { value: 'today', label: 'Hoy' },
    { value: 'week', label: 'Semana' },
    { value: 'month', label: 'Mes' },
    { value: 'custom', label: 'Personalizado' },
];

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);
}

/**
 * branchFilter:
 *  - 'active' (default): no envía ?branch — el backend filtra por la sede activa del JWT.
 *  - 'all': envía ?branch=all → modo consolidado (requiere metrics.view_all_branches).
 *  - <uuid>: envía ?branch=<uuid> para ver una sede específica distinta a la activa
 *           (también requiere metrics.view_all_branches).
 */
function buildQuery(period: MetricPeriod, dateFrom: string, dateTo: string, branchFilter: string): string {
    const params = new URLSearchParams({ period });
    if (period === 'custom' && dateFrom && dateTo) {
        params.set('date_from', dateFrom);
        params.set('date_to', dateTo);
    }
    if (branchFilter !== 'active') {
        params.set('branch', branchFilter);
    }
    return params.toString();
}

function computeChange(current: number, previous: number): number | null {
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / previous) * 100;
}

interface MetricsPageProps {
    foodCostAlertThreshold?: string;
}

export default function MetricsIndex({ foodCostAlertThreshold = '0.30' }: MetricsPageProps) {
    const activeToken = useToken();
    const alertThreshold = Math.max(0, Math.min(1, parseFloat(foodCostAlertThreshold) || 0.3));
    const [period, setPeriod] = useState<MetricPeriod>('today');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [appliedDateFrom, setAppliedDateFrom] = useState('');
    const [appliedDateTo, setAppliedDateTo] = useState('');

    const [branchFilter, setBranchFilter] = useState<string>('active');

    const [summary, setSummary] = useState<MetricSummary | null>(null);
    const [summaryLoading, setSummaryLoading] = useState(true);
    const [lastUpdated, setLastUpdated] = useState<Date | undefined>();

    const [activeOrders, setActiveOrders] = useState<MetricActiveOrders | null>(null);
    const [activeOrdersLoading, setActiveOrdersLoading] = useState(true);
    const [activeOrdersRefreshedAt, setActiveOrdersRefreshedAt] = useState<string | undefined>();

    const [topItems, setTopItems] = useState<MetricTopItems | null>(null);
    const [topItemsLoading, setTopItemsLoading] = useState(true);

    const [dishMargin, setDishMargin] = useState<MetricDishMargin | null>(null);
    const [dishMarginLoading, setDishMarginLoading] = useState(true);

    const [abandonment, setAbandonment] = useState<MetricCartAbandonment | null>(null);
    const [abandonmentLoading, setAbandonmentLoading] = useState(true);

    const [heatmap, setHeatmap] = useState<MetricHeatmap | null>(null);
    const [heatmapLoading, setHeatmapLoading] = useState(true);

    const [weeklyHeatmap, setWeeklyHeatmap] = useState<MetricWeeklyHeatmap | null>(null);
    const [weeklyHeatmapLoading, setWeeklyHeatmapLoading] = useState(true);

    const isMounted = useRef(true);

    const isCustomReady = period !== 'custom' || (appliedDateFrom !== '' && appliedDateTo !== '');

    const fetchSummary = useCallback(async () => {
        if (!activeToken || !isCustomReady) return;
        setSummaryLoading(true);
        try {
            const res = await apiFetch(`/api/v1/metrics/summary?${buildQuery(period, appliedDateFrom, appliedDateTo, branchFilter)}`);
            if (!isMounted.current) return;
            if (res.ok) {
                const json = await res.json();
                setSummary(json.data);
                setLastUpdated(new Date());
            }
        } finally {
            if (isMounted.current) setSummaryLoading(false);
        }
    }, [activeToken, period, appliedDateFrom, appliedDateTo, isCustomReady, branchFilter]);

    const fetchActiveOrders = useCallback(async () => {
        if (!activeToken) return;
        try {
            const branchParam = branchFilter !== 'active' ? `?branch=${encodeURIComponent(branchFilter)}` : '';
            const res = await apiFetch(`/api/v1/metrics/orders/active${branchParam}`);
            if (!isMounted.current) return;
            if (res.ok) {
                const json = await res.json();
                setActiveOrders(json.data);
                setActiveOrdersRefreshedAt(json.refreshed_at);
            }
        } finally {
            if (isMounted.current) setActiveOrdersLoading(false);
        }
    }, [activeToken, branchFilter]);

    const fetchTopItems = useCallback(async () => {
        if (!activeToken || !isCustomReady) return;
        setTopItemsLoading(true);
        try {
            const res = await apiFetch(`/api/v1/metrics/items/top?${buildQuery(period, appliedDateFrom, appliedDateTo, branchFilter)}`);
            if (!isMounted.current) return;
            if (res.ok) {
                const json = await res.json();
                setTopItems(json.data);
            }
        } finally {
            if (isMounted.current) setTopItemsLoading(false);
        }
    }, [activeToken, period, appliedDateFrom, appliedDateTo, isCustomReady, branchFilter]);

    const fetchDishMargin = useCallback(async () => {
        if (!activeToken || !isCustomReady) return;
        setDishMarginLoading(true);
        try {
            const res = await apiFetch(`/api/v1/metrics/dishes/margin?${buildQuery(period, appliedDateFrom, appliedDateTo, branchFilter)}`);
            if (!isMounted.current) return;
            if (res.ok) {
                const json = await res.json();
                setDishMargin(json.data);
            }
        } finally {
            if (isMounted.current) setDishMarginLoading(false);
        }
    }, [activeToken, period, appliedDateFrom, appliedDateTo, isCustomReady, branchFilter]);

    const fetchAbandonment = useCallback(async () => {
        if (!activeToken || !isCustomReady) return;
        setAbandonmentLoading(true);
        try {
            const res = await apiFetch(`/api/v1/metrics/carts/abandonment?${buildQuery(period, appliedDateFrom, appliedDateTo, branchFilter)}`);
            if (!isMounted.current) return;
            if (res.ok) {
                const json = await res.json();
                setAbandonment(json.data);
            }
        } finally {
            if (isMounted.current) setAbandonmentLoading(false);
        }
    }, [activeToken, period, appliedDateFrom, appliedDateTo, isCustomReady, branchFilter]);

    const fetchHeatmap = useCallback(async () => {
        if (!activeToken || !isCustomReady) return;
        setHeatmapLoading(true);
        // Heatmap Part 2 supports today|week|custom — map month → week
        const heatmapPeriod: MetricPeriod = period === 'month' ? 'week' : period;
        try {
            const res = await apiFetch(`/api/v1/metrics/orders/heatmap?${buildQuery(heatmapPeriod, appliedDateFrom, appliedDateTo, branchFilter)}`);
            if (!isMounted.current) return;
            if (res.ok) {
                const json = await res.json();
                setHeatmap(json.data);
            }
        } finally {
            if (isMounted.current) setHeatmapLoading(false);
        }
    }, [activeToken, period, appliedDateFrom, appliedDateTo, isCustomReady, branchFilter]);

    const fetchWeeklyHeatmap = useCallback(async () => {
        if (!activeToken || !isCustomReady) return;
        setWeeklyHeatmapLoading(true);
        // Weekly heatmap uses 'week' by default; for custom pass dates
        const whPeriod: MetricPeriod = period === 'today' ? 'week' : period;
        try {
            const res = await apiFetch(`/api/v1/metrics/orders/heatmap/weekly?${buildQuery(whPeriod, appliedDateFrom, appliedDateTo, branchFilter)}`);
            if (!isMounted.current) return;
            if (res.ok) {
                const json = await res.json();
                setWeeklyHeatmap(json.data);
            }
        } finally {
            if (isMounted.current) setWeeklyHeatmapLoading(false);
        }
    }, [activeToken, period, appliedDateFrom, appliedDateTo, isCustomReady, branchFilter]);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    // Summary se carga una vez; el operador activa el switch en vivo si necesita
    // refresco automatico (60s, con auto-off a los 5 min).
    useEffect(() => {
        fetchSummary();
    }, [fetchSummary]);

    const summaryLive = useLivePolling({ intervalMs: 30_000, onTick: fetchSummary });

    // Active orders polling every 30s (real-time, period-independent)
    useEffect(() => {
        fetchActiveOrders();
        const poll = setInterval(fetchActiveOrders, 30_000);
        return () => clearInterval(poll);
    }, [fetchActiveOrders]);

    // Period-dependent metrics
    useEffect(() => {
        fetchTopItems();
        fetchAbandonment();
        fetchHeatmap();
        fetchWeeklyHeatmap();
        fetchDishMargin();
    }, [fetchTopItems, fetchAbandonment, fetchHeatmap, fetchWeeklyHeatmap, fetchDishMargin]);

    function handlePeriodChange(newPeriod: MetricPeriod) {
        setPeriod(newPeriod);
        if (newPeriod !== 'custom') {
            setAppliedDateFrom('');
            setAppliedDateTo('');
        }
    }

    function handleApplyCustom() {
        setAppliedDateFrom(dateFrom);
        setAppliedDateTo(dateTo);
    }

    const comparison = summary?.comparison ?? null;
    const ordersChange = comparison ? computeChange(summary!.total_orders, comparison.total_orders) : null;
    const revenueChange = comparison ? computeChange(summary!.total_revenue, comparison.total_revenue) : null;
    const changeLabel = comparison?.period_label ? `vs ${comparison.period_label}` : undefined;

    return (
        <PageShell title="Métricas operativas">
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="OPERACIÓN"
                    title="Métricas operativas"
                    description="Monitoreo en tiempo real de tu empresa."
                    variant="editorial"
                    actions={
                        <>
                            <LivePollingToggle {...summaryLive} onToggle={summaryLive.toggle} />
                            <ExportPdfButton
                                endpoint="/api/v1/exports/metrics/pdf"
                                filters={{
                                    period,
                                    ...(period === 'custom' && appliedDateFrom ? { date_from: appliedDateFrom } : {}),
                                    ...(period === 'custom' && appliedDateTo ? { date_to: appliedDateTo } : {}),
                                    ...(branchFilter !== 'active' ? { branch: branchFilter } : {}),
                                }}
                                filename={`metricas_${new Date().toISOString().slice(0, 10)}.pdf`}
                                disabled={!summary}
                            />
                            <LiveIndicator timestamp={lastUpdated} isLive={!summaryLoading} />
                        </>
                    }
                />

                {/* Filtros: sede + período (Multi-sede #117). Stack en mobile, wrap en sm+. */}
                <div className="bg-card flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <BranchFilterTabs value={branchFilter} onChange={setBranchFilter} />
                    <PeriodTabs
                        options={PERIOD_OPTIONS}
                        value={period}
                        onChange={handlePeriodChange}
                        customValue="custom"
                        dateFrom={dateFrom}
                        dateTo={dateTo}
                        onDateFromChange={setDateFrom}
                        onDateToChange={setDateTo}
                        onApplyCustom={handleApplyCustom}
                        applyDisabled={!dateFrom || !dateTo}
                    />
                </div>

                {/* KPI row */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <KpiCard
                        label="Órdenes"
                        value={summary?.total_orders ?? 0}
                        sub={`${summary?.completed_orders ?? 0} completadas`}
                        icon={ShoppingBag}
                        loading={summaryLoading}
                        changePercent={ordersChange}
                        changeLabel={changeLabel}
                    />
                    <KpiCard
                        label="Ingresos"
                        value={summary ? formatCurrency(summary.total_revenue) : '—'}
                        sub={`Ticket promedio ${summary ? formatCurrency(summary.average_ticket) : '—'}`}
                        icon={DollarSign}
                        loading={summaryLoading}
                        accent
                        changePercent={revenueChange}
                        changeLabel={changeLabel}
                    />
                    <KpiCard
                        label="Órdenes activas"
                        value={summary?.orders_in_progress ?? 0}
                        sub="en curso ahora"
                        icon={Activity}
                        loading={summaryLoading}
                    />
                    <KpiCard
                        label="Canceladas / Abandonadas"
                        value={`${summary?.cancelled_orders ?? 0} / ${summary?.abandoned_carts ?? 0}`}
                        sub="en el período"
                        icon={TrendingDown}
                        loading={summaryLoading}
                    />
                </div>

                {/* Middle row: active orders + item ranking */}
                <div className="grid gap-4 md:grid-cols-2">
                    <ActiveOrdersPanel data={activeOrders} loading={activeOrdersLoading} refreshedAt={activeOrdersRefreshedAt} />
                    <DishRankingPanel data={topItems} loading={topItemsLoading} formatCurrency={formatCurrency} />
                </div>

                {/* Charts row: hourly heatmap + abandonment */}
                <div className="grid gap-4 md:grid-cols-2">
                    <HeatmapPanel data={heatmap} loading={heatmapLoading} />
                    <AbandonmentPanel data={abandonment} loading={abandonmentLoading} formatCurrency={formatCurrency} />
                </div>

                {/* Margen por plato */}
                <DishMarginPanel data={dishMargin} loading={dishMarginLoading} formatCurrency={formatCurrency} />

                {/* Food cost (issue #113) */}
                <FoodCostPanel
                    period={period}
                    dateFrom={appliedDateFrom}
                    dateTo={appliedDateTo}
                    formatCurrency={formatCurrency}
                    alertThreshold={alertThreshold}
                    enabled={isCustomReady && !!activeToken}
                />

                {/* Operación offline (issue #140) */}
                <OfflineOperationPanel period={period} formatCurrency={formatCurrency} enabled={isCustomReady && !!activeToken} />

                {/* Menu engineering (issue #114) */}
                <MenuEngineeringPanel
                    period={period}
                    dateFrom={appliedDateFrom}
                    dateTo={appliedDateTo}
                    formatCurrency={formatCurrency}
                    enabled={isCustomReady && !!activeToken}
                />

                {/* Weekly heatmap: 7×24 grid */}
                <DashboardPanel title="Actividad semanal por hora">
                    <HeatmapChart data={weeklyHeatmap} loading={weeklyHeatmapLoading} />
                </DashboardPanel>
            </div>
        </PageShell>
    );
}
