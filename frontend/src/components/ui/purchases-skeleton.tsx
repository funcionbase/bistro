import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface PurchasesSkeletonProps {
    /** Cantidad de órdenes a esqueletizar (default 6). */
    rows?: number;
    className?: string;
}

/**
 * Esqueleto fiel de `/purchases`.
 *
 * Replica:
 *  - PageHeader (eyebrow + título + descripción + 2 acciones).
 *  - 4 StatTile (Total / Borradores / Pendientes pago / Reintegros).
 *  - FilterBar (search + 2 selects + checkbox).
 *  - Mobile (<sm): card-stack con código + proveedor + 4 fields.
 *  - Desktop (≥sm): tabla 6 cols (Código, Estado, Proveedor, Esperada,
 *    Total, Pago).
 *
 * Ver FRONTEND_UI_GUIDELINES §10 (responsive tables) y §13 (loading).
 */
export function PurchasesSkeleton({ rows = 6, className }: PurchasesSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando órdenes de compra" className={cn('space-y-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-24 rounded-full" />
                    <Skeleton className="h-9 w-72" />
                    <Skeleton className="h-4 w-full max-w-xl" />
                </div>
                <div className="flex flex-wrap gap-2">
                    <Skeleton className="h-9 w-28 rounded-md" />
                    <Skeleton className="h-9 w-32 rounded-md" />
                </div>
            </div>

            {/* StatTiles */}
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="border-border bg-card space-y-2 rounded-2xl border p-5 shadow-sm">
                        <Skeleton className="h-8 w-16" />
                        <Skeleton className="h-3 w-32" />
                    </div>
                ))}
            </div>

            {/* FilterBar */}
            <div className="border-border bg-card flex flex-col gap-3 rounded-2xl border p-3 shadow-sm sm:flex-row sm:items-end sm:p-4">
                <Skeleton className="h-9 w-full sm:max-w-xs" />
                <div className="flex flex-wrap items-end gap-3">
                    <div className="space-y-1">
                        <Skeleton className="h-3 w-14" />
                        <Skeleton className="h-9 w-36 rounded-md" />
                    </div>
                    <div className="space-y-1">
                        <Skeleton className="h-3 w-20" />
                        <Skeleton className="h-9 w-44 rounded-md" />
                    </div>
                    <Skeleton className="h-4 w-40" />
                </div>
            </div>

            {/* Mobile card-stack */}
            <ul className="space-y-3 sm:hidden">
                {Array.from({ length: rows }).map((_, i) => (
                    <li key={i} className="border-border bg-card space-y-3 rounded-2xl border p-4 shadow-sm">
                        <div className="space-y-1.5">
                            <Skeleton className="h-4 w-24" />
                            <Skeleton className="h-3 w-36" />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            {[0, 1, 2, 3].map((j) => (
                                <div key={j} className="space-y-1">
                                    <Skeleton className="h-2.5 w-14" />
                                    <Skeleton className="h-3.5 w-20" />
                                </div>
                            ))}
                        </div>
                    </li>
                ))}
            </ul>

            {/* Desktop table */}
            <div className="border-border hidden overflow-hidden rounded-lg border sm:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-xs uppercase">
                        <tr>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-16" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-16" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-24" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-20" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-14" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-14" />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {Array.from({ length: rows }).map((_, i) => (
                            <tr key={i} className="border-border border-t">
                                <td className="px-4 py-3">
                                    <Skeleton className="h-4 w-20" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-5 w-16 rounded-full" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-4 w-36" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-4 w-20" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="ml-auto h-4 w-20" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-4 w-16" />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
