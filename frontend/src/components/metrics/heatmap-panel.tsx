import SalesHeatmap from '@/components/dashboard/sales-heatmap';
import HeatmapSkeleton from '@/components/dashboard/skeleton/heatmap-skeleton';
import WidgetErrorState from '@/components/dashboard/widget-error-state';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import type { MetricHeatmap } from '@/types';

interface HeatmapPanelProps {
    data: MetricHeatmap | null;
    loading?: boolean;
    error?: boolean;
    retryFn?: () => void;
}

function formatHour(hour: number): string {
    return `${String(hour).padStart(2, '0')}:00`;
}

export default function HeatmapPanel({ data, loading = false, error = false, retryFn }: HeatmapPanelProps) {
    if (loading && !data) {
        return <HeatmapSkeleton />;
    }

    const rightSlot =
        data?.peak_hour != null ? (
            <span className="text-muted-foreground text-xs">
                Pico: {formatHour(data.peak_hour)} ({data.peak_hour_orders} órdenes)
            </span>
        ) : undefined;

    return (
        <DashboardPanel title="Actividad por hora" rightSlot={rightSlot}>
            {error && retryFn ? (
                <WidgetErrorState onRetry={retryFn} />
            ) : (
                <SalesHeatmap
                    data={data?.hours ?? []}
                    peakHour={data?.peak_hour ?? null}
                    currentHour={data?.current_hour}
                    loading={loading || !data}
                />
            )}
        </DashboardPanel>
    );
}
