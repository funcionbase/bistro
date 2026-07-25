import { Skeleton } from '@/components/ui/skeleton';
import type { MetricHeatmapHour } from '@/types';
import { Bar, BarChart, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { formatCurrency } from '@/lib/formatters';

interface TooltipProps {
    active?: boolean;
    payload?: Array<{ name: string; value: number; payload: MetricHeatmapHour }>;
    label?: number;
}

function HeatmapTooltip({ active, payload, label }: TooltipProps) {
    if (!active || !payload?.length) return null;
    const hour = label ?? 0;
    const entry = payload[0];
    return (
        <div className="bg-popover text-popover-foreground rounded-lg border px-3 py-2 shadow-md">
            <p className="mb-1 text-sm font-semibold">{`${String(hour).padStart(2, '0')}:00`}</p>
            <p className="text-xs text-[var(--color-primary)]">Órdenes: {entry.value}</p>
            <p className="text-muted-foreground text-xs">
                Ingresos:{' '}
                {formatCurrency(entry.payload.revenue)}
            </p>
        </div>
    );
}

interface SalesHeatmapProps {
    data: MetricHeatmapHour[];
    peakHour: number | null;
    currentHour?: number;
    loading?: boolean;
}

export default function SalesHeatmap({ data, currentHour, loading = false }: SalesHeatmapProps) {
    if (loading) {
        return <Skeleton className="h-[220px] w-full" />;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <BarChart data={data} margin={{ top: 4, right: 4, left: -28, bottom: 0 }} barSize={8}>
                <XAxis
                    dataKey="hour"
                    tick={{ fontSize: 10 }}
                    tickFormatter={(h: number) => (h % 4 === 0 ? `${h}h` : '')}
                    axisLine={false}
                    tickLine={false}
                />
                <YAxis tick={{ fontSize: 10 }} allowDecimals={false} axisLine={false} tickLine={false} />
                <Tooltip content={<HeatmapTooltip />} cursor={{ fill: 'var(--color-body)' }} />
                <Bar dataKey="orders_count" name="Órdenes" radius={[3, 3, 0, 0]}>
                    {data.map((entry) => (
                        <Cell
                            key={`cell-${entry.hour}`}
                            fill={
                                entry.hour === currentHour
                                    ? 'var(--color-secondary)'
                                    : // color-mix sobre el token real (patrón de heatmap-chart.tsx):
                                      // --color-primary-rgb no existe y el rgba() caía siempre al indigo.
                                      `color-mix(in oklch, var(--color-primary) ${Math.round(Math.max(0.2, entry.intensity) * 100)}%, transparent)`
                            }
                        />
                    ))}
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}
