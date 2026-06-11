import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface DeliveryMetricsSkeletonProps {
    /** Cantidad de repartidores a esqueletizar (default 5). */
    rows?: number;
    className?: string;
}

/**
 * Esqueleto fiel de `/deliveries/metrics`.
 *
 * Replica:
 *  - PageHeader (eyebrow + título + descripción + botón refresh).
 *  - PeriodTabs (3 pills).
 *  - 4 StatTile (Total / Completadas / Canceladas / Tasa éxito).
 *  - DashboardPanel "Rendimiento por repartidor" con:
 *      · Mobile (<sm): card-stack con nombre + 4 fields + barra.
 *      · Desktop (≥sm): tabla 7 cols (Repartidor, Entregas,
 *        Completadas, Canceladas, Prom. duración, Tasa éxito,
 *        Rendimiento).
 *
 * Ver FRONTEND_UI_GUIDELINES §10 (responsive tables) y §13 (loading).
 */
export function DeliveryMetricsSkeleton({ rows = 5, className }: DeliveryMetricsSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando métricas de repartidores" className={cn('space-y-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-32 rounded-full" />
                    <Skeleton className="h-9 w-72" />
                    <Skeleton className="h-4 w-full max-w-md" />
                </div>
                <Skeleton className="h-9 w-9 rounded-md self-start md:self-auto" />
            </div>

            {/* PeriodTabs */}
            <div className="flex gap-2">
                <Skeleton className="h-9 w-20 rounded-md" />
                <Skeleton className="h-9 w-28 rounded-md" />
                <Skeleton className="h-9 w-24 rounded-md" />
            </div>

            {/* StatTiles */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="border-border bg-card space-y-2 rounded-2xl border p-5 shadow-sm">
                        <Skeleton className="h-8 w-20" />
                        <Skeleton className="h-3 w-28" />
                    </div>
                ))}
            </div>

            {/* DashboardPanel */}
            <div className="border-border bg-card rounded-2xl border p-4 shadow-sm sm:p-6">
                <Skeleton className="mb-4 h-5 w-48" />

                {/* Mobile card-stack */}
                <ul className="space-y-3 sm:hidden">
                    {Array.from({ length: rows }).map((_, i) => (
                        <li key={i} className="border-border bg-background space-y-3 rounded-2xl border p-4">
                            <Skeleton className="h-4 w-40" />
                            <div className="grid grid-cols-2 gap-3">
                                {[0, 1, 2, 3].map((j) => (
                                    <div key={j} className="space-y-1">
                                        <Skeleton className="h-2.5 w-14" />
                                        <Skeleton className="h-3.5 w-16" />
                                    </div>
                                ))}
                            </div>
                            <Skeleton className="h-2 w-full rounded-full" />
                        </li>
                    ))}
                </ul>

                {/* Desktop table */}
                <div className="border-border hidden overflow-hidden rounded-lg border sm:block">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs uppercase">
                            <tr>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-20" />
                                </th>
                                <th className="px-4 py-3 text-center">
                                    <Skeleton className="mx-auto h-3 w-16" />
                                </th>
                                <th className="px-4 py-3 text-center">
                                    <Skeleton className="mx-auto h-3 w-20" />
                                </th>
                                <th className="px-4 py-3 text-center">
                                    <Skeleton className="mx-auto h-3 w-16" />
                                </th>
                                <th className="px-4 py-3 text-center">
                                    <Skeleton className="mx-auto h-3 w-24" />
                                </th>
                                <th className="px-4 py-3 text-center">
                                    <Skeleton className="mx-auto h-3 w-16" />
                                </th>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-24" />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {Array.from({ length: rows }).map((_, i) => (
                                <tr key={i} className="border-border border-t">
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-4 w-32" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="mx-auto h-4 w-8" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="mx-auto h-4 w-8" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="mx-auto h-4 w-8" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="mx-auto h-4 w-14" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="mx-auto h-4 w-12" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-2 w-full rounded-full" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
