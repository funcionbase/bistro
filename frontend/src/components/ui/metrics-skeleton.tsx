import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface MetricsSkeletonProps {
    className?: string;
}

/** Panel rectangular con título + cuerpo (calca DashboardPanel cargando). */
function PanelSkeleton({ bodyHeight = 'h-48' }: { bodyHeight?: string }) {
    return (
        <div className="border-border bg-card space-y-4 rounded-2xl border p-5 shadow-sm">
            <div className="flex items-center justify-between">
                <Skeleton className="h-5 w-40" />
                <Skeleton className="h-4 w-20" />
            </div>
            <Skeleton className={cn('w-full rounded-lg', bodyHeight)} />
        </div>
    );
}

/**
 * Skeleton fiel de `/company/metrics`.
 *
 * Replica:
 *  - PageHeader (eyebrow + título + descripción + acciones de período).
 *  - Grid de 4 KpiCard.
 *  - 2 filas de 2 paneles (órdenes activas / ranking, heatmap / abandono).
 *  - Paneles full-width (margen por plato, food cost, heatmap semanal).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §13 (loading).
 */
export function MetricsSkeleton({ className }: MetricsSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando métricas" className={cn('space-y-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-24 rounded-full" />
                    <Skeleton className="h-9 w-48" />
                    <Skeleton className="h-4 w-full max-w-md" />
                </div>
                <div className="flex flex-wrap gap-2">
                    <Skeleton className="h-9 w-32 rounded-md" />
                    <Skeleton className="h-9 w-28 rounded-md" />
                </div>
            </div>

            {/* KPI cards */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="border-border bg-card space-y-3 rounded-2xl border p-5 shadow-sm">
                        <div className="flex items-center justify-between">
                            <Skeleton className="h-3 w-20" />
                            <Skeleton className="h-8 w-8 rounded-lg" />
                        </div>
                        <Skeleton className="h-8 w-28" />
                        <Skeleton className="h-3 w-32" />
                    </div>
                ))}
            </div>

            {/* Fila de paneles: órdenes activas / ranking */}
            <div className="grid gap-4 lg:grid-cols-2">
                <PanelSkeleton />
                <PanelSkeleton />
            </div>

            {/* Fila de charts: heatmap / abandono */}
            <div className="grid gap-4 lg:grid-cols-2">
                <PanelSkeleton bodyHeight="h-56" />
                <PanelSkeleton bodyHeight="h-56" />
            </div>

            {/* Paneles full-width */}
            <PanelSkeleton bodyHeight="h-40" />
            <PanelSkeleton bodyHeight="h-64" />
        </div>
    );
}
