import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface ClientsListSkeletonProps {
    /** Filas a esqueletizar en mobile y desktop. Default 6. */
    rows?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/clients` (CRM consolidado).
 *
 * Replica:
 *  - PageHeader (eyebrow CRM + título + 2 acciones).
 *  - FilterBar (search + chips segmento + select etiqueta).
 *  - Mobile (<sm): card-stack con nombre + teléfono + segmento + 4 fields
 *    (pedidos / ticket / total / última orden).
 *  - Desktop (≥sm): tabla 7 cols (Cliente, Pedidos, Ticket prom., Total,
 *    Última orden, Segmento, Etiquetas).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §10 (responsive tables) y §13 (loading).
 */
export function ClientsListSkeleton({ rows = 6, className }: ClientsListSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando clientes" className={cn('space-y-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-16 rounded-full" />
                    <Skeleton className="h-9 w-52" />
                    <Skeleton className="h-4 w-full max-w-lg" />
                </div>
                <div className="flex flex-col gap-2 sm:flex-row md:shrink-0">
                    <Skeleton className="h-9 w-full sm:w-28" />
                    <Skeleton className="h-9 w-full sm:w-36" />
                </div>
            </div>

            {/* FilterBar */}
            <div className="border-border bg-card flex flex-col gap-3 rounded-lg border p-3 shadow-sm sm:flex-row sm:flex-wrap sm:items-center">
                <Skeleton className="h-9 w-full sm:max-w-sm sm:flex-1" />
                <div className="flex flex-wrap items-center gap-2">
                    <Skeleton className="h-3 w-16" />
                    <Skeleton className="h-6 w-12 rounded" />
                    <Skeleton className="h-6 w-16 rounded" />
                    <Skeleton className="h-6 w-20 rounded" />
                    <Skeleton className="h-6 w-16 rounded" />
                </div>
            </div>

            {/* Mobile card-stack */}
            <ul className="space-y-3 sm:hidden">
                {Array.from({ length: rows }).map((_, i) => (
                    <li
                        key={i}
                        className="border-border bg-card space-y-3 rounded-lg border p-4 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0 flex-1 space-y-1.5">
                                <Skeleton className="h-4 w-2/3" />
                                <Skeleton className="h-3 w-32" />
                            </div>
                            <Skeleton className="h-5 w-20 shrink-0 rounded-full" />
                        </div>
                        <div className="grid grid-cols-2 gap-x-3 gap-y-2">
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-14" />
                                <Skeleton className="h-3.5 w-10" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-16" />
                                <Skeleton className="h-3.5 w-16" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-12" />
                                <Skeleton className="h-3.5 w-20" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-20" />
                                <Skeleton className="h-3.5 w-16" />
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-1">
                            <Skeleton className="h-5 w-14 rounded-full" />
                            <Skeleton className="h-5 w-16 rounded-full" />
                        </div>
                    </li>
                ))}
            </ul>

            {/* Desktop table */}
            <div className="bg-card hidden overflow-hidden rounded-lg border shadow-sm sm:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-xs uppercase">
                        <tr>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-20" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-16" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-20" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-20" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-24" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-20" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-20" />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {Array.from({ length: rows }).map((_, i) => (
                            <tr key={i} className="border-border border-t">
                                <td className="px-4 py-3">
                                    <Skeleton className="h-4 w-28" />
                                    <Skeleton className="mt-1 h-3 w-32" />
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-4 w-8" />
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-4 w-16" />
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-4 w-20" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-3 w-16" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-5 w-20 rounded-full" />
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex gap-1">
                                        <Skeleton className="h-5 w-14 rounded-full" />
                                        <Skeleton className="h-5 w-12 rounded-full" />
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
