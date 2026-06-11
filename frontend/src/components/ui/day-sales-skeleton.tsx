import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface DaySalesSkeletonProps {
    /** Cantidad de pedidos a esqueletizar (default 6). */
    rows?: number;
    className?: string;
}

/**
 * Esqueleto fiel de `/deliveries` (Ventas del día).
 *
 * Replica:
 *  - PageHeader (eyebrow + título + descripción + 2 acciones).
 *  - 5 StatTile (Total / Completadas / Canceladas / Devoluciones / Abandonadas).
 *  - 3 KpiCell (Ingresos brutos / Devoluciones / Ingresos netos).
 *  - Filtro de estado (Label + select).
 *  - Listado de pedidos:
 *      · Mobile (<sm): card-stack con #id + estado + dirección + repartidor + total.
 *      · Desktop (≥sm): tabla 7 cols (#, Fecha/Hora, Tipo, Estado, Cliente, Repartidor, Total).
 *
 * Ver FRONTEND_UI_GUIDELINES §10 (responsive tables) y §13 (loading).
 */
export function DaySalesSkeleton({ rows = 6, className }: DaySalesSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando ventas del día" className={cn('space-y-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-24 rounded-full" />
                    <Skeleton className="h-9 w-60" />
                    <Skeleton className="h-4 w-full max-w-lg" />
                </div>
                <div className="flex flex-col gap-2 sm:flex-row md:shrink-0">
                    <Skeleton className="h-9 w-full sm:w-28" />
                    <Skeleton className="h-9 w-full sm:w-28" />
                </div>
            </div>

            {/* StatTiles — 5 KPIs */}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {[0, 1, 2, 3, 4].map((i) => (
                    <div key={i} className="border-border bg-card space-y-2 rounded-2xl border p-5 shadow-sm">
                        <Skeleton className="h-8 w-16" />
                        <Skeleton className="h-3 w-24" />
                    </div>
                ))}
            </div>

            {/* KpiCell — 3 totales contables */}
            <div className="grid gap-3 sm:grid-cols-3">
                {[0, 1, 2].map((i) => (
                    <div key={i} className="border-border bg-card space-y-1.5 rounded-lg border p-4 shadow-sm">
                        <Skeleton className="h-3 w-28" />
                        <Skeleton className="h-6 w-32" />
                    </div>
                ))}
            </div>

            {/* Filtro */}
            <div className="flex flex-wrap items-center gap-2">
                <Skeleton className="h-4 w-28" />
                <Skeleton className="h-8 w-40" />
            </div>

            {/* Lista de pedidos */}
            <div>
                {/* Mobile card-stack */}
                <ul className="space-y-2 sm:hidden">
                    {Array.from({ length: rows }).map((_, i) => (
                        <li key={i} className="border-border bg-card space-y-2 rounded-lg border p-3 shadow-sm">
                            <div className="flex items-start justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <Skeleton className="h-4 w-4 rounded" />
                                    <Skeleton className="h-4 w-12" />
                                    <Skeleton className="h-5 w-20 rounded-full" />
                                </div>
                                <Skeleton className="h-4 w-20" />
                            </div>
                            <div className="flex items-center justify-between gap-2">
                                <Skeleton className="h-3 w-32" />
                                <Skeleton className="h-3 w-24" />
                            </div>
                            <Skeleton className="h-3 w-40" />
                        </li>
                    ))}
                </ul>

                {/* Desktop table */}
                <div className="bg-card hidden overflow-hidden rounded-lg border shadow-sm sm:block">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs uppercase">
                            <tr>
                                <th className="px-3 py-2 text-left">
                                    <Skeleton className="h-3 w-6" />
                                </th>
                                <th className="px-3 py-2 text-left">
                                    <Skeleton className="h-3 w-20" />
                                </th>
                                <th className="px-3 py-2 text-left">
                                    <Skeleton className="h-3 w-12" />
                                </th>
                                <th className="px-3 py-2 text-left">
                                    <Skeleton className="h-3 w-16" />
                                </th>
                                <th className="px-3 py-2 text-left">
                                    <Skeleton className="h-3 w-32" />
                                </th>
                                <th className="px-3 py-2 text-left">
                                    <Skeleton className="h-3 w-20" />
                                </th>
                                <th className="px-3 py-2 text-right">
                                    <Skeleton className="ml-auto h-3 w-12" />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {Array.from({ length: rows }).map((_, i) => (
                                <tr key={i} className="border-border border-t">
                                    <td className="px-3 py-2">
                                        <Skeleton className="h-4 w-10" />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Skeleton className="h-3 w-28" />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Skeleton className="h-4 w-16" />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Skeleton className="h-5 w-20 rounded-full" />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Skeleton className="h-3 w-40" />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Skeleton className="h-3 w-24" />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Skeleton className="ml-auto h-4 w-16" />
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
