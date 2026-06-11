import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface CajaTableSessionSkeletonProps {
    /** Comensales a esqueletizar. Default 3. */
    guests?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/caja/table-sessions/{id}` (cobro por comensal).
 *
 * Replica:
 *  - PageHeader (eyebrow Caja + título + 2 acciones).
 *  - Grid responsive de GuestItemList (1/2/3 cols).
 *  - Sección de comprobantes emitidos.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §13 (loading).
 */
export function CajaTableSessionSkeleton({ guests = 3, className }: CajaTableSessionSkeletonProps) {
    return (
        <div
            aria-busy="true"
            aria-label="Cargando cobro de mesa"
            className={cn('flex h-full flex-1 flex-col gap-6', className)}
        >
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-4 w-16 rounded-full" />
                    <Skeleton className="h-8 w-56" />
                    <Skeleton className="h-3.5 w-full max-w-md" />
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Skeleton className="h-9 w-28" />
                    <Skeleton className="h-9 w-44" />
                </div>
            </div>

            {/* Guests grid */}
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {Array.from({ length: guests }).map((_, i) => (
                    <div
                        key={i}
                        className="border-border bg-card space-y-3 rounded-2xl border p-4 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="space-y-1">
                                <Skeleton className="h-4 w-32" />
                                <Skeleton className="h-3 w-24" />
                            </div>
                            <Skeleton className="h-5 w-20 rounded-full" />
                        </div>
                        {Array.from({ length: 3 }).map((_, j) => (
                            <div key={j} className="flex items-start gap-2">
                                <Skeleton className="h-4 w-4 shrink-0 rounded" />
                                <div className="flex-1 space-y-1">
                                    <Skeleton className="h-3.5 w-2/3" />
                                    <Skeleton className="h-3 w-1/3" />
                                </div>
                                <Skeleton className="h-3.5 w-14" />
                            </div>
                        ))}
                        <div className="border-border flex items-center justify-between border-t pt-2">
                            <Skeleton className="h-3.5 w-24" />
                            <Skeleton className="h-4 w-20" />
                        </div>
                        <div className="flex gap-2">
                            <Skeleton className="h-9 flex-1" />
                            <Skeleton className="h-9 flex-1" />
                        </div>
                    </div>
                ))}
            </div>

            {/* Comprobantes */}
            <section className="space-y-2">
                <Skeleton className="h-4 w-44" />
                <div className="border-border bg-card divide-y rounded-2xl border">
                    {Array.from({ length: 3 }).map((_, i) => (
                        <div
                            key={i}
                            className="flex flex-wrap items-center justify-between gap-2 px-4 py-2"
                        >
                            <div className="flex items-center gap-2">
                                <Skeleton className="h-5 w-16 rounded-full" />
                                <Skeleton className="h-3.5 w-16" />
                                <Skeleton className="h-3 w-24" />
                            </div>
                            <Skeleton className="h-3 w-32" />
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}
