import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface TablesGridSkeletonProps {
    /** Cards a esqueletizar. Default 12. */
    count?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/orders/tables` (visualización de mesas).
 *
 * Replica:
 *  - Banner CashRegister + PageHeader.
 *  - Grid responsive 2/3/4/6 cols con TableCards (número grande arriba,
 *    métricas debajo).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.4 (grid de cards) y §13 (loading).
 */
export function TablesGridSkeleton({ count = 12, className }: TablesGridSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando mesas" className={cn('flex flex-col gap-6', className)}>
            {/* CashRegister banner */}
            <div className="border-border bg-card flex flex-col gap-3 rounded-lg border p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                    <Skeleton className="h-10 w-10 rounded-full" />
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-40" />
                        <Skeleton className="h-3 w-28" />
                    </div>
                </div>
                <Skeleton className="h-9 w-full sm:w-32" />
            </div>

            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-4 w-20 rounded-full" />
                    <Skeleton className="h-8 w-28" />
                    <Skeleton className="h-3.5 w-full max-w-xl" />
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Skeleton className="h-9 w-28" />
                    <Skeleton className="h-9 w-28" />
                    <Skeleton className="h-9 w-36" />
                </div>
            </div>

            {/* Grid de mesas */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                {Array.from({ length: count }).map((_, i) => (
                    <div
                        key={i}
                        className="border-border bg-card flex h-28 flex-col justify-between rounded-lg border p-3 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <Skeleton className="h-7 w-10" />
                            <Skeleton className="h-4 w-12 rounded-full" />
                        </div>
                        <div className="space-y-1">
                            <Skeleton className="h-3 w-16" />
                            <Skeleton className="h-3.5 w-20" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
