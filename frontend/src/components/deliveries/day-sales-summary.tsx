import type { DaySalesSummary as DaySalesSummaryData } from '@/hooks/use-day-sales';
import { KpiCell } from '@/components/ui/kpi-cell';
import { StatTile } from '@/components/ui/stat-tile';

interface DaySalesSummaryProps {
    summary: DaySalesSummaryData | null;
    formatCurrency: (value: number) => string;
}

/**
 * KPIs operativos (conteos por estado) y resumen contable (bruto /
 * devoluciones / neto) de la página de ventas del día. Extraído para
 * aligerar la página — comportamiento idéntico.
 */
export function DaySalesSummary({ summary, formatCurrency }: DaySalesSummaryProps) {
    const kpis: Array<{ label: string; value: number; tone: 'default' | 'safe' | 'warning' | 'critical' }> = [
        { label: 'Total', value: summary?.total_orders ?? 0, tone: 'default' },
        { label: 'Completadas', value: summary?.completed ?? 0, tone: 'safe' },
        { label: 'Canceladas', value: summary?.cancelled ?? 0, tone: 'critical' },
        { label: 'Devoluciones', value: summary?.refunded ?? 0, tone: 'critical' },
        { label: 'Abandonadas', value: summary?.abandoned ?? 0, tone: 'warning' },
    ];

    return (
        <>
            {/* KPIs */}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {kpis.map((k) => (
                    <StatTile key={k.label} value={k.value} label={k.label} tone={k.tone} size="lg" />
                ))}
            </div>

            {/* Resumen contable */}
            {summary && (
                <div className="grid gap-3 sm:grid-cols-3">
                    <KpiCell label="Ingresos brutos" value={formatCurrency(summary.total_revenue)} />
                    <KpiCell
                        label="Devoluciones"
                        value={
                            <span className="text-[color:var(--color-status-critical)]">{formatCurrency(summary.total_refunded ?? 0)}</span>
                        }
                    />
                    <KpiCell
                        label="Ingresos netos"
                        value={
                            <span className="text-[color:var(--color-status-safe)]">
                                {formatCurrency(summary.net_revenue ?? summary.total_revenue)}
                            </span>
                        }
                    />
                </div>
            )}
        </>
    );
}
