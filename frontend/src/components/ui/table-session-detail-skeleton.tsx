import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface TableSessionDetailSkeletonProps {
    className?: string;
}

/**
 * Skeleton fiel de `/orders/table-sessions/{id}` (detalle de sesión QR).
 *
 * Replica:
 *  - PageHeader (eyebrow + título + 2 acciones).
 *  - Mobile (<lg): main + aside apilados.
 *  - Desktop (≥lg): main 2/3 + aside 1/3.
 *  - Main: comensales (chips), órdenes de la mesa (cards con items), tabs.
 *  - Aside: card resumen.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.1 y §13 (loading).
 */
export function TableSessionDetailSkeleton({ className }: TableSessionDetailSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando sesión" className={cn('flex h-full flex-1 flex-col gap-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-4 w-32 rounded-full" />
                    <Skeleton className="h-8 w-32" />
                    <Skeleton className="h-3.5 w-64" />
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Skeleton className="h-9 w-32" />
                    <Skeleton className="h-9 w-36" />
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {/* Comensales */}
                    <section className="space-y-2">
                        <Skeleton className="h-4 w-28" />
                        <div className="flex flex-wrap gap-2">
                            {Array.from({ length: 4 }).map((_, i) => (
                                <Skeleton key={i} className="h-7 w-24 rounded-full" />
                            ))}
                        </div>
                    </section>

                    {/* Órdenes de la mesa */}
                    <section className="space-y-3">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                            <Skeleton className="h-4 w-44" />
                            <Skeleton className="h-3 w-56" />
                        </div>
                        <ul className="space-y-3">
                            {Array.from({ length: 2 }).map((_, i) => (
                                <li
                                    key={i}
                                    className="border-border bg-card space-y-2 rounded-xl border p-3"
                                >
                                    <div className="flex items-baseline justify-between gap-2">
                                        <div className="flex items-baseline gap-2">
                                            <Skeleton className="h-4 w-20" />
                                            <Skeleton className="h-3 w-16" />
                                        </div>
                                        <Skeleton className="h-4 w-16" />
                                    </div>
                                    {Array.from({ length: 3 }).map((_, j) => (
                                        <div key={j} className="flex items-start justify-between gap-2">
                                            <Skeleton className="h-3.5 w-2/3" />
                                            <Skeleton className="h-3.5 w-14" />
                                        </div>
                                    ))}
                                </li>
                            ))}
                        </ul>
                    </section>

                    {/* Tabs items */}
                    <section className="space-y-3">
                        <Skeleton className="h-4 w-32" />
                        <div className="flex flex-wrap gap-1">
                            {Array.from({ length: 6 }).map((_, i) => (
                                <Skeleton key={i} className="h-8 w-20 rounded-md" />
                            ))}
                        </div>
                        <ul className="space-y-2 pt-2">
                            {Array.from({ length: 3 }).map((_, i) => (
                                <li
                                    key={i}
                                    className="border-border bg-card flex items-start justify-between gap-2 rounded-lg border p-3"
                                >
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <Skeleton className="h-4 w-3/4" />
                                        <Skeleton className="h-3 w-1/2" />
                                    </div>
                                    <Skeleton className="h-4 w-14" />
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>

                {/* Aside resumen */}
                <aside className="space-y-6">
                    <section className="space-y-2">
                        <Skeleton className="h-4 w-24" />
                        <div className="border-border bg-card space-y-2 rounded-2xl border p-4">
                            <div className="flex items-center justify-between">
                                <Skeleton className="h-3.5 w-16" />
                                <Skeleton className="h-5 w-20 rounded-full" />
                            </div>
                            <div className="flex items-center justify-between">
                                <Skeleton className="h-3.5 w-28" />
                                <Skeleton className="h-4 w-16" />
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    );
}
