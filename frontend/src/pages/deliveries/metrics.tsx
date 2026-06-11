import { CourierMetricsPanel } from '@/components/deliveries/courier-metrics-panel';
import { PerformanceBar } from '@/components/deliveries/performance-bar';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { DeliveryMetricsSkeleton } from '@/components/ui/delivery-metrics-skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { PeriodTabs } from '@/components/ui/period-tabs';
import { StatTile } from '@/components/ui/stat-tile';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDeliveryMetrics, type MetricPeriodOption } from '@/hooks/use-delivery-metrics';
import { useToken } from '@/hooks/use-token';
import { cn } from '@/lib/utils';
import type { DeliveryMetric } from '@/types';

import { ChevronDown, ChevronUp, ChevronsUpDown, RefreshCw, Truck } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';


const PERIOD_OPTIONS: ReadonlyArray<{ value: MetricPeriodOption; label: string }> = [
    { value: 'today', label: 'Hoy' },
    { value: 'week', label: 'Esta semana' },
    { value: 'month', label: 'Este mes' },
];

type SortKey = keyof DeliveryMetric;
type SortDir = 'asc' | 'desc';

interface ColumnDef {
    key: SortKey;
    label: string;
    align: 'left' | 'center';
}

const COLUMNS: ReadonlyArray<ColumnDef> = [
    { key: 'courier_name', label: 'Repartidor', align: 'left' },
    { key: 'total_deliveries', label: 'Entregas', align: 'center' },
    { key: 'completed', label: 'Completadas', align: 'center' },
    { key: 'cancelled', label: 'Canceladas', align: 'center' },
    { key: 'average_duration_minutes', label: 'Prom. duración', align: 'center' },
    { key: 'success_rate', label: 'Tasa éxito', align: 'center' },
];

function SortIcon({ column, sort }: { column: SortKey; sort: { key: SortKey; dir: SortDir } }) {
    if (sort.key !== column) {
        return <ChevronsUpDown className="text-muted-foreground/60 ml-1 inline h-3.5 w-3.5" />;
    }
    return sort.dir === 'asc' ? (
        <ChevronUp className="text-primary ml-1 inline h-3.5 w-3.5" />
    ) : (
        <ChevronDown className="text-primary ml-1 inline h-3.5 w-3.5" />
    );
}

export default function DeliveryMetricsPage() {
    const token = useToken();
    const { metrics, loading, period, changePeriod, fetchMetrics } = useDeliveryMetrics(token);
    const [sort, setSort] = useState<{ key: SortKey; dir: SortDir }>({ key: 'total_deliveries', dir: 'desc' });

    useEffect(() => {
        void fetchMetrics();
    }, [fetchMetrics]);

    function toggleSort(key: SortKey) {
        setSort((prev) => (prev.key === key ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'desc' }));
    }

    const sorted = useMemo(() => {
        return [...metrics].sort((a, b) => {
            const va = a[sort.key] ?? 0;
            const vb = b[sort.key] ?? 0;
            const cmp = typeof va === 'string' ? va.localeCompare(String(vb)) : (va as number) - (vb as number);
            return sort.dir === 'asc' ? cmp : -cmp;
        });
    }, [metrics, sort]);

    const maxSuccessRate = useMemo(() => Math.max(0, ...metrics.map((m) => parseFloat(m.success_rate))), [metrics]);

    const totals = useMemo(() => {
        const totalDeliveries = metrics.reduce((acc, m) => acc + m.total_deliveries, 0);
        const completed = metrics.reduce((acc, m) => acc + m.completed, 0);
        const cancelled = metrics.reduce((acc, m) => acc + m.cancelled, 0);
        const avgRate = totalDeliveries > 0 ? Math.round((completed / totalDeliveries) * 100) : null;
        return { totalDeliveries, completed, cancelled, avgRate };
    }, [metrics]);

    return (
        <PageShell title="Métricas de Repartidores">
            <div className="p-4 sm:p-6">
                {loading && metrics.length === 0 ? (
                    <DeliveryMetricsSkeleton />
                ) : (
                    <div className="space-y-6">
                        <PageHeader
                            eyebrow="REPARTIDORES"
                            title="Métricas de repartidores"
                            description="Rendimiento comparativo por período: entregas, tasa de éxito y duración promedio."
                            variant="editorial"
                            actions={
                                <Button variant="outline" size="icon" onClick={() => void fetchMetrics()} disabled={loading} title="Actualizar">
                                    <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} />
                                </Button>
                            }
                        />

                        <PeriodTabs<MetricPeriodOption> options={PERIOD_OPTIONS} value={period} onChange={changePeriod} />

                        <div className="grid gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
                            <StatTile size="lg" value={totals.totalDeliveries} label="Total entregas" />
                            <StatTile size="lg" tone="safe" value={totals.completed} label="Completadas" />
                            <StatTile size="lg" tone="critical" value={totals.cancelled} label="Canceladas" />
                            <StatTile
                                size="lg"
                                tone="primary"
                                value={totals.avgRate !== null ? `${totals.avgRate}%` : '—'}
                                label="Tasa éxito promedio"
                            />
                        </div>

                        <DashboardPanel title="Rendimiento por repartidor">
                            {sorted.length === 0 ? (
                                <EmptyState
                                    icon={Truck}
                                    title="Sin datos para el período seleccionado"
                                    description="Cambiá de período o esperá a que entren entregas para ver el comparativo."
                                />
                            ) : (
                                <>
                                    {/* Mobile card-stack */}
                                    <ul className="space-y-3 sm:hidden" aria-label="Métricas por repartidor">
                                        {sorted.map((metric) => (
                                            <CourierMetricsCard key={metric.user_id} metric={metric} maxSuccessRate={maxSuccessRate} />
                                        ))}
                                    </ul>

                                    {/* Desktop table */}
                                    <div className="bg-card hidden overflow-hidden rounded-lg border sm:block">
                                        <Table bare>
                                            <TableHeader>
                                                <TableRow>
                                                    {COLUMNS.map(({ key, label, align }) => (
                                                        <TableHead
                                                            key={key}
                                                            onClick={() => toggleSort(key)}
                                                            className={cn(
                                                                'hover:bg-muted/60 cursor-pointer font-semibold select-none',
                                                                align === 'center' ? 'text-center' : 'text-left',
                                                            )}
                                                        >
                                                            {label}
                                                            <SortIcon column={key} sort={sort} />
                                                        </TableHead>
                                                    ))}
                                                    <TableHead className="font-semibold">Rendimiento</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {sorted.map((metric) => (
                                                    <CourierMetricsPanel key={metric.user_id} metric={metric} maxSuccessRate={maxSuccessRate} />
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </>
                            )}
                        </DashboardPanel>
                    </div>
                )}
            </div>
        </PageShell>
    );
}

/**
 * Variante card-stack mobile (<sm) de la fila de métricas de repartidor.
 * Misma información que `CourierMetricsPanel` (fila de tabla desktop) pero
 * organizada como dl 2x2 con barra de rendimiento al pie. Ver
 * FRONTEND_UI_GUIDELINES.md §10 — toda tabla con ≥6 columnas debe ofrecer
 * variante card-stack.
 */
function CourierMetricsCard({ metric, maxSuccessRate }: { metric: DeliveryMetric; maxSuccessRate: number }) {
    const successRate = parseFloat(metric.success_rate);
    const rateColor =
        successRate >= 80
            ? 'text-[color:var(--color-status-safe)]'
            : successRate >= 50
              ? 'text-[color:var(--color-status-warning)]'
              : 'text-[color:var(--color-status-critical)]';
    const relativePercent = maxSuccessRate > 0 ? Math.round((successRate / maxSuccessRate) * 100) : 0;

    return (
        <li className="border-border bg-card space-y-3 rounded-2xl border p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <span className="text-foreground truncate text-sm font-semibold">{metric.courier_name}</span>
                <span className={cn('shrink-0 text-sm font-semibold tabular-nums', rateColor)}>{metric.success_rate}</span>
            </div>
            <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                <div>
                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">Entregas</dt>
                    <dd className="text-foreground tabular-nums">{metric.total_deliveries}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">Completadas</dt>
                    <dd className="text-[color:var(--color-status-safe)] tabular-nums">{metric.completed}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">Canceladas</dt>
                    <dd className="text-[color:var(--color-status-critical)] tabular-nums">{metric.cancelled}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">Prom. duración</dt>
                    <dd className="text-foreground tabular-nums">
                        {metric.average_duration_minutes !== null ? `${metric.average_duration_minutes} min` : '—'}
                    </dd>
                </div>
            </dl>
            <div className="border-border/60 border-t pt-3">
                <PerformanceBar percentage={relativePercent} label={`${Math.round(successRate)}%`} />
            </div>
        </li>
    );
}
