import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface ReportsTableSkeletonProps {
    /** Cantidad de filas. Default 6. */
    rows?: number;
    className?: string;
}

/**
 * Esqueleto fiel de la tabla de pedidos en `/company/reports`. Mantiene la
 * misma silueta (id mono + tipo + badge + total + costo + fecha) y, en
 * mobile, replica la card-stack alternativa para que no haya layout shift al
 * pasar a datos reales.
 */
export function ReportsTableSkeleton({ rows = 6, className }: ReportsTableSkeletonProps) {
    return (
        <div className={cn(className)} aria-busy="true">
            {/* Mobile card-stack */}
            <ul className="space-y-3 px-4 pb-4 sm:hidden">
                {Array.from({ length: rows }).map((_, i) => (
                    <li
                        key={i}
                        className="border-border bg-card space-y-3 rounded-2xl border p-4"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0 space-y-1.5">
                                <Skeleton className="h-3 w-16" />
                                <Skeleton className="h-4 w-24" />
                            </div>
                            <Skeleton className="h-5 w-20 rounded-full" />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-14" />
                                <Skeleton className="h-3.5 w-20" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-14" />
                                <Skeleton className="h-3.5 w-20" />
                            </div>
                        </div>
                        <Skeleton className="h-3 w-32" />
                    </li>
                ))}
            </ul>

            {/* Desktop table rows */}
            <div className="hidden sm:block">
                <ul className="space-y-2 px-4 py-3">
                    {Array.from({ length: rows }).map((_, i) => (
                        <li key={i} className="flex items-center gap-3">
                            <Skeleton className="h-4 w-14" />
                            <Skeleton className="h-4 w-20" />
                            <Skeleton className="h-5 w-20 rounded-full" />
                            <Skeleton className="h-4 w-20" />
                            <Skeleton className="h-4 w-20" />
                            <Skeleton className="h-4 w-32" />
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
