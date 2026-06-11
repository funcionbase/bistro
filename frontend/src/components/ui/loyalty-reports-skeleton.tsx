import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface LoyaltyReportsSkeletonProps {
    className?: string;
}

/**
 * Esqueleto fiel de `/loyalty/reports`.
 *
 * Replica la disposición real:
 *  - PageHeader + filtros de fecha (Desde / Hasta / Refresh).
 *  - 4 StatTile (Puntos otorgados / canjeados / expirados / clientes activos).
 *  - Grid 2 cards (Tasa de canje + Distribución por tier).
 *  - Card ARPU por tier (tabla mobile cards / desktop tabla).
 *  - Card Top clientes (tabla mobile cards / desktop tabla 6 cols).
 *  - Card Expiraciones (3 KpiCell).
 *
 * Ver FRONTEND_UI_GUIDELINES §10 (responsive tables) y §13 (loading).
 */
export function LoyaltyReportsSkeleton({ className }: LoyaltyReportsSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando reportes de fidelización" className={cn('flex flex-col gap-6', className)}>
            {/* PageHeader + filtros */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-32 rounded-full" />
                    <Skeleton className="h-9 w-40" />
                    <Skeleton className="h-4 w-full max-w-md" />
                </div>
                <div className="flex flex-wrap items-end gap-2">
                    <div className="space-y-1">
                        <Skeleton className="h-3 w-12" />
                        <Skeleton className="h-9 w-32" />
                    </div>
                    <div className="space-y-1">
                        <Skeleton className="h-3 w-12" />
                        <Skeleton className="h-9 w-32" />
                    </div>
                    <Skeleton className="h-9 w-9 rounded-md" />
                </div>
            </div>

            {/* 4 StatTiles */}
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="border-border bg-card space-y-2 rounded-2xl border p-5 shadow-sm">
                        <Skeleton className="h-8 w-20" />
                        <Skeleton className="h-3 w-24" />
                    </div>
                ))}
            </div>

            {/* Tasa de canje + Distribución tiers */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="border-border bg-card space-y-3 rounded-xl border p-6">
                    <Skeleton className="h-5 w-32" />
                    <Skeleton className="h-9 w-24" />
                    <div className="grid grid-cols-2 gap-2">
                        {[0, 1, 2, 3].map((i) => (
                            <div key={i} className="border-border space-y-1.5 rounded-lg border p-3">
                                <Skeleton className="h-3 w-16" />
                                <Skeleton className="h-5 w-12" />
                            </div>
                        ))}
                    </div>
                    <Skeleton className="h-3 w-48" />
                </div>

                <div className="border-border bg-card space-y-3 rounded-xl border p-6">
                    <Skeleton className="h-5 w-56" />
                    <div className="space-y-2">
                        {[0, 1, 2].map((i) => (
                            <div key={i} className="flex items-center justify-between gap-2">
                                <Skeleton className="h-5 w-20 rounded-full" />
                                <Skeleton className="h-3 w-36" />
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* ARPU por tier */}
            <div className="border-border bg-card space-y-3 rounded-xl border p-6">
                <Skeleton className="h-5 w-32" />

                <ul className="space-y-3 sm:hidden">
                    {[0, 1, 2].map((i) => (
                        <li key={i} className="border-border space-y-2 rounded-lg border p-3">
                            <div className="flex items-center justify-between">
                                <Skeleton className="h-5 w-20 rounded-full" />
                                <Skeleton className="h-4 w-20" />
                            </div>
                            <div className="grid grid-cols-3 gap-2">
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-12" />
                                    <Skeleton className="h-3.5 w-10" />
                                </div>
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-12" />
                                    <Skeleton className="h-3.5 w-16" />
                                </div>
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-10" />
                                    <Skeleton className="h-3.5 w-14" />
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>

                <div className="border-border hidden overflow-hidden rounded-lg border sm:block">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs uppercase">
                            <tr>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-12" />
                                </th>
                                <th className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-3 w-16" />
                                </th>
                                <th className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-3 w-28" />
                                </th>
                                <th className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-3 w-12" />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {[0, 1, 2].map((i) => (
                                <tr key={i} className="border-border border-t">
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-5 w-16 rounded-full" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="ml-auto h-4 w-10" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="ml-auto h-4 w-20" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="ml-auto h-4 w-20" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Top clientes */}
            <div className="border-border bg-card space-y-3 rounded-xl border p-6">
                <Skeleton className="h-5 w-48" />

                <ul className="space-y-3 sm:hidden">
                    {[0, 1, 2, 3].map((i) => (
                        <li key={i} className="border-border space-y-2 rounded-lg border p-3">
                            <div className="flex items-center justify-between gap-2">
                                <Skeleton className="h-3 w-32" />
                                <Skeleton className="h-5 w-16 rounded-full" />
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-14" />
                                    <Skeleton className="h-3.5 w-16" />
                                </div>
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-14" />
                                    <Skeleton className="h-3.5 w-16" />
                                </div>
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-16" />
                                    <Skeleton className="h-3.5 w-14" />
                                </div>
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-16" />
                                    <Skeleton className="h-3.5 w-14" />
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>

                <div className="border-border hidden overflow-hidden rounded-lg border sm:block">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs uppercase">
                            <tr>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-20" />
                                </th>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-10" />
                                </th>
                                <th className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-3 w-16" />
                                </th>
                                <th className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-3 w-16" />
                                </th>
                                <th className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-3 w-24" />
                                </th>
                                <th className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-3 w-24" />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {[0, 1, 2, 3, 4].map((i) => (
                                <tr key={i} className="border-border border-t">
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-4 w-32" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-5 w-16 rounded-full" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="ml-auto h-4 w-16" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="ml-auto h-4 w-16" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="ml-auto h-4 w-20" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="ml-auto h-4 w-20" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Expiraciones */}
            <div className="border-border bg-card space-y-3 rounded-xl border p-6">
                <Skeleton className="h-5 w-28" />
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="border-border space-y-1.5 rounded-lg border p-3">
                            <Skeleton className="h-3 w-24" />
                            <Skeleton className="h-5 w-16" />
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
