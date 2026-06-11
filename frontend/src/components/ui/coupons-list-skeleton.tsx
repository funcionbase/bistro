import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface CouponsListSkeletonProps {
    /** Filas a esqueletizar en mobile y desktop. Default 5. */
    rows?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/coupons` (cupones de descuento).
 *
 * Replica:
 *  - PageHeader (eyebrow + título + 3 acciones).
 *  - Filtros (search + 4 chips).
 *  - Mobile (<sm): card-stack con código + 4 fields + footer de iconos.
 *  - Desktop (≥sm): tabla 8 cols (Código, Tipo, Valor, Estado, Usos,
 *    Válido hasta, Programación, Acciones).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §10 (responsive tables) y §13 (loading).
 */
export function CouponsListSkeleton({ rows = 5, className }: CouponsListSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando cupones" className={cn('space-y-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-28 rounded-full" />
                    <Skeleton className="h-9 w-72" />
                    <Skeleton className="h-4 w-full max-w-md" />
                </div>
                <div className="flex flex-col gap-2 sm:flex-row md:shrink-0">
                    <Skeleton className="h-9 w-full sm:w-28" />
                    <Skeleton className="h-9 w-full sm:w-28" />
                    <Skeleton className="h-9 w-full sm:w-32" />
                </div>
            </div>

            {/* Filtros */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Skeleton className="h-9 w-full rounded-md sm:w-64" />
                <div className="flex flex-wrap gap-2">
                    <Skeleton className="h-7 w-16 rounded-md" />
                    <Skeleton className="h-7 w-20 rounded-md" />
                    <Skeleton className="h-7 w-24 rounded-md" />
                    <Skeleton className="h-7 w-20 rounded-md" />
                </div>
            </div>

            <div className="bg-card overflow-hidden rounded-lg border shadow-sm">
                {/* Mobile card-stack */}
                <ul className="space-y-3 p-4 sm:hidden">
                    {Array.from({ length: rows }).map((_, i) => (
                        <li
                            key={i}
                            className="border-border bg-card space-y-3 rounded-lg border p-4 shadow-sm"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0 flex-1 space-y-1.5">
                                    <Skeleton className="h-4 w-32" />
                                    <Skeleton className="h-3 w-20 rounded-full" />
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-x-3 gap-y-2">
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-12" />
                                    <Skeleton className="h-3.5 w-16" />
                                </div>
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-12" />
                                    <Skeleton className="h-5 w-16 rounded-full" />
                                </div>
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-12" />
                                    <Skeleton className="h-3.5 w-12" />
                                </div>
                                <div className="space-y-1">
                                    <Skeleton className="h-2.5 w-16" />
                                    <Skeleton className="h-3.5 w-20" />
                                </div>
                            </div>
                            <div className="border-border/60 flex items-center justify-end gap-2 border-t pt-3">
                                <Skeleton className="h-8 w-8 rounded-md" />
                                <Skeleton className="h-8 w-8 rounded-md" />
                                <Skeleton className="h-8 w-8 rounded-md" />
                                <Skeleton className="h-8 w-8 rounded-md" />
                            </div>
                        </li>
                    ))}
                </ul>

                {/* Desktop table */}
                <div className="hidden overflow-x-auto sm:block">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs uppercase">
                            <tr>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-16" />
                                </th>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-12" />
                                </th>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-12" />
                                </th>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-14" />
                                </th>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-10" />
                                </th>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-20" />
                                </th>
                                <th className="px-4 py-3 text-left">
                                    <Skeleton className="h-3 w-24" />
                                </th>
                                <th className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-3 w-16" />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {Array.from({ length: rows }).map((_, i) => (
                                <tr key={i} className="border-border border-t">
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-4 w-24" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-5 w-20 rounded-full" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-4 w-14" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-5 w-16 rounded-full" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-4 w-10" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-4 w-20" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-4 w-28" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center justify-end gap-1">
                                            <Skeleton className="h-7 w-7 rounded-md" />
                                            <Skeleton className="h-7 w-7 rounded-md" />
                                            <Skeleton className="h-7 w-7 rounded-md" />
                                            <Skeleton className="h-7 w-7 rounded-md" />
                                        </div>
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
