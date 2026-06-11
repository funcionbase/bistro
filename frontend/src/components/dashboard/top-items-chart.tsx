import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type { MetricTopItem } from '@/types';
import { useState } from 'react';
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const VISIBLE_DEFAULT = 10;

interface TooltipProps {
    active?: boolean;
    payload?: Array<{ payload: MetricTopItem }>;
    formatCurrency: (v: number) => string;
}

function ItemTooltip({ active, payload, formatCurrency }: TooltipProps) {
    if (!active || !payload?.length) return null;
    const { name, count, revenue } = payload[0].payload;
    return (
        <div className="rounded-lg border bg-white px-3 py-2 shadow-md">
            <p className="max-w-[180px] truncate text-sm font-semibold">{name}</p>
            <p className="text-xs">{count} veces pedido</p>
            <p className="text-muted-foreground text-xs">{formatCurrency(revenue)}</p>
        </div>
    );
}

interface TopItemsChartProps {
    data: MetricTopItem[];
    loading?: boolean;
    formatCurrency: (v: number) => string;
}

export default function TopItemsChart({ data, loading = false, formatCurrency }: TopItemsChartProps) {
    const [expanded, setExpanded] = useState(false);

    if (loading) {
        return (
            <div className="space-y-2">
                {Array.from({ length: 5 }).map((_, i) => (
                    <Skeleton key={i} className="h-8 w-full" />
                ))}
            </div>
        );
    }

    if (data.length === 0) {
        return <p className="text-muted-foreground py-6 text-center text-sm">Sin datos para este período</p>;
    }

    const visibleData = expanded ? data : data.slice(0, VISIBLE_DEFAULT);
    const hiddenCount = data.length - VISIBLE_DEFAULT;
    const chartHeight = Math.max(visibleData.length * 44, 160);

    return (
        <div>
            <ResponsiveContainer width="100%" height={chartHeight}>
                <BarChart data={visibleData} layout="vertical" margin={{ top: 0, right: 52, left: 0, bottom: 0 }}>
                    <XAxis type="number" hide />
                    <YAxis
                        dataKey="name"
                        type="category"
                        width={130}
                        tick={{ fontSize: 12 }}
                        tickFormatter={(v: string) => (v.length > 18 ? `${v.slice(0, 17)}…` : v)}
                        axisLine={false}
                        tickLine={false}
                    />
                    <Tooltip content={<ItemTooltip formatCurrency={formatCurrency} />} cursor={{ fill: 'var(--color-body)' }} />
                    <Bar dataKey="count" radius={[0, 4, 4, 0]}>
                        {visibleData.map((_, i) => (
                            <Cell key={i} fill="var(--color-accent-blue)" opacity={Math.max(1 - i * 0.07, 0.4)} />
                        ))}
                        <LabelList
                            dataKey="count"
                            position="right"
                            // eslint-disable-next-line @typescript-eslint/no-explicit-any
                            formatter={(v: any) => `${v}×`}
                            style={{ fontSize: 12, fill: 'var(--color-body-dark)' }}
                        />
                    </Bar>
                </BarChart>
            </ResponsiveContainer>

            {hiddenCount > 0 && (
                <div className="mt-2 text-center">
                    <Button variant="ghost" size="sm" className="text-xs" onClick={() => setExpanded((e) => !e)}>
                        {expanded ? 'Ver menos' : `Ver ${hiddenCount} más`}
                    </Button>
                </div>
            )}
        </div>
    );
}
