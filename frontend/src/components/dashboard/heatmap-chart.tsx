import { Skeleton } from '@/components/ui/skeleton';
import type { MetricWeeklyHeatmap } from '@/types';

const DAY_ORDER = [1, 2, 3, 4, 5, 6, 0]; // Mon→Sun
const DAY_LABELS: Record<number, string> = {
    0: 'Dom',
    1: 'Lun',
    2: 'Mar',
    3: 'Mié',
    4: 'Jue',
    5: 'Vie',
    6: 'Sáb',
};
const DAY_FULL: Record<number, string> = {
    0: 'Domingo',
    1: 'Lunes',
    2: 'Martes',
    3: 'Miércoles',
    4: 'Jueves',
    5: 'Viernes',
    6: 'Sábado',
};

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);
}

interface HeatmapChartProps {
    data: MetricWeeklyHeatmap | null;
    loading?: boolean;
}

export default function HeatmapChart({ data, loading = false }: HeatmapChartProps) {
    if (loading || !data) {
        return <Skeleton className="h-44 w-full" />;
    }

    const { cells, max_orders: maxOrders } = data;

    // Build lookup: {day}_{hour} → cell
    const cellMap = new Map(cells.map((c) => [`${c.day}_${c.hour}`, c]));

    const getOpacity = (orders: number) => {
        if (maxOrders === 0 || orders === 0) return 0.06;
        return 0.06 + (orders / maxOrders) * 0.94;
    };

    const hours = Array.from({ length: 24 }, (_, i) => i);

    return (
        <div className="w-full overflow-x-auto">
            <div className="min-w-[560px]">
                {/* Hour labels */}
                <div className="mb-1 flex pl-10">
                    {hours.map((h) => (
                        <div key={h} className="text-muted-foreground flex-1 text-center text-[9px]">
                            {h % 4 === 0 ? `${h}h` : ''}
                        </div>
                    ))}
                </div>

                {/* Grid rows */}
                {DAY_ORDER.map((dow) => (
                    <div key={dow} className="mb-0.5 flex items-center gap-1">
                        <span className="text-muted-foreground w-9 shrink-0 text-right text-[10px]">{DAY_LABELS[dow]}</span>
                        <div className="flex flex-1 gap-0.5">
                            {hours.map((h) => {
                                const cell = cellMap.get(`${dow}_${h}`);
                                const orders = cell?.orders ?? 0;
                                const revenue = cell?.revenue ?? 0;
                                const opacity = getOpacity(orders);
                                return (
                                    <div
                                        key={h}
                                        className="h-5 flex-1 cursor-default rounded-sm"
                                        style={{ backgroundColor: `rgba(0, 82, 255, ${opacity.toFixed(2)})` }}
                                        title={`${DAY_FULL[dow]} ${String(h).padStart(2, '0')}:00 — ${orders} órdenes${revenue > 0 ? ` — ${formatCurrency(revenue)}` : ''}`}
                                    />
                                );
                            })}
                        </div>
                    </div>
                ))}

                {/* Legend */}
                <div className="mt-2 flex items-center justify-end gap-2">
                    <span className="text-muted-foreground text-[10px]">Menos</span>
                    <div className="flex gap-0.5">
                        {[0.06, 0.28, 0.5, 0.72, 1].map((op) => (
                            <div key={op} className="h-3 w-3 rounded-sm" style={{ backgroundColor: `rgba(0, 82, 255, ${op})` }} />
                        ))}
                    </div>
                    <span className="text-muted-foreground text-[10px]">Más</span>
                </div>
            </div>
        </div>
    );
}
