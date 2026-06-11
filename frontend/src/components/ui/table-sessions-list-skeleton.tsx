import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface TableSessionsListSkeletonProps {
    /** Cards a esqueletizar. Default 6. */
    count?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/orders/table-sessions` (lista de sesiones QR).
 *
 * Replica:
 *  - PageHeader (eyebrow Mesa con QR + título + 2 acciones: live toggle, refrescar).
 *  - Grid 1/2/3 cols con SessionCards (mesa N + meta + badges + total).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.4 (grid de cards) y §13 (loading).
 */
export function TableSessionsListSkeleton({ count = 6, className }: TableSessionsListSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando sesiones de mesa" className={cn('flex h-full flex-1 flex-col gap-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-4 w-28 rounded-full" />
                    <Skeleton className="h-8 w-56" />
                    <Skeleton className="h-3.5 w-full max-w-md" />
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Skeleton className="h-9 w-32" />
                    <Skeleton className="h-9 w-28" />
                </div>
            </div>

            {/* Grid de cards */}
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                {Array.from({ length: count }).map((_, i) => (
                    <div
                        key={i}
                        className="border-border bg-card flex flex-col gap-3 rounded-2xl border p-4 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="space-y-1.5">
                                <Skeleton className="h-5 w-24" />
                                <Skeleton className="h-3 w-32" />
                            </div>
                            <Skeleton className="h-5 w-24 rounded-full" />
                        </div>
                        <div className="flex items-center gap-3">
                            <Skeleton className="h-3 w-24" />
                            <Skeleton className="h-3 w-28" />
                        </div>
                        <div className="border-border flex items-center justify-between border-t pt-2">
                            <Skeleton className="h-3 w-24" />
                            <Skeleton className="h-4 w-16" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
