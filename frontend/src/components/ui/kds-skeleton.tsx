import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface KdsSkeletonProps {
    /** Tickets a esqueletizar. Default 6. */
    tickets?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/kds` (Kitchen Display System).
 *
 * Replica:
 *  - DesktopOnlyHint (banner aviso mobile).
 *  - PageHeader (eyebrow Cocina + título + 2 acciones).
 *  - Filtros segmentados (4 botones).
 *  - Grid de KdsTicketCard 1/2/3/4 cols.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §13 (loading).
 */
export function KdsSkeleton({ tickets = 6, className }: KdsSkeletonProps) {
    return (
        <div
            aria-busy="true"
            aria-label="Cargando tablero de cocina"
            className={cn('flex h-full flex-1 flex-col gap-6', className)}
        >
            {/* DesktopOnlyHint solo mobile */}
            <div className="border-border bg-card flex items-start gap-3 rounded-lg border p-4 md:hidden">
                <Skeleton className="h-5 w-5 rounded-full" />
                <div className="flex-1 space-y-2">
                    <Skeleton className="h-4 w-1/2" />
                    <Skeleton className="h-3 w-full" />
                </div>
            </div>

            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-4 w-20 rounded-full" />
                    <Skeleton className="h-8 w-56" />
                    <Skeleton className="h-3.5 w-64" />
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Skeleton className="h-9 w-32" />
                    <Skeleton className="h-9 w-28" />
                </div>
            </div>

            {/* Filtros */}
            <div className="flex flex-wrap items-center gap-2">
                {Array.from({ length: 4 }).map((_, i) => (
                    <Skeleton key={i} className="h-9 w-24 rounded-md" />
                ))}
            </div>

            {/* Grid de tickets */}
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {Array.from({ length: tickets }).map((_, i) => (
                    <div
                        key={i}
                        className="border-border bg-card space-y-3 rounded-xl border p-4 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="space-y-1">
                                <Skeleton className="h-5 w-20" />
                                <Skeleton className="h-3 w-16" />
                            </div>
                            <Skeleton className="h-5 w-20 rounded-full" />
                        </div>
                        <div className="space-y-2">
                            <Skeleton className="h-4 w-full" />
                            <Skeleton className="h-4 w-3/4" />
                        </div>
                        <Skeleton className="h-10 w-full" />
                    </div>
                ))}
            </div>
        </div>
    );
}
