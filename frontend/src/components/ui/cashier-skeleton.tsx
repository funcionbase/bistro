import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface CashierSkeletonProps {
    /** Cuántos ítems del catálogo dibujar. Default 6. */
    items?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/orders/cashier` (POS).
 *
 * Replica:
 *  - Banner de caja (header con badge + 2 acciones).
 *  - Panel de mesas con cuentas pendientes.
 *  - PageHeader (eyebrow POS + título + badge "Menú activo").
 *  - Mobile (<md): catálogo full-width (grid 1/2 cols) y carrito apilado debajo.
 *  - Desktop (≥md): grid 1fr / 360px con catálogo a la izquierda y carrito sticky a la derecha.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.1 (default block) y §13 (loading).
 */
export function CashierSkeleton({ items = 6, className }: CashierSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando caja" className={cn('space-y-4', className)}>
            {/* CashRegister banner */}
            <div className="border-border bg-card flex flex-col gap-3 rounded-lg border p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                    <Skeleton className="h-10 w-10 rounded-full" />
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-40" />
                        <Skeleton className="h-3 w-28" />
                    </div>
                </div>
                <div className="flex gap-2">
                    <Skeleton className="h-9 w-full sm:w-28" />
                    <Skeleton className="h-9 w-full sm:w-32" />
                </div>
            </div>

            {/* Billable tables panel */}
            <div className="border-border bg-card space-y-2 rounded-lg border p-3 shadow-sm">
                <Skeleton className="h-3.5 w-44" />
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    {Array.from({ length: 3 }).map((_, i) => (
                        <Skeleton key={i} className="h-12 w-full rounded-md" />
                    ))}
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-[1fr_360px] xl:grid-cols-[1fr_400px]">
                {/* Catálogo */}
                <div className="min-w-0 space-y-4">
                    {/* PageHeader */}
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-16 rounded-full" />
                        <div className="flex items-end justify-between gap-3">
                            <Skeleton className="h-8 w-44" />
                            <Skeleton className="h-6 w-24 rounded-full" />
                        </div>
                    </div>

                    {/* Categorías + grid de ítems */}
                    {[1, 2].map((cat) => (
                        <div key={cat} className="space-y-2">
                            <Skeleton className="h-3.5 w-32" />
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {Array.from({ length: items / 2 }).map((_, i) => (
                                    <div
                                        key={i}
                                        className="border-border bg-card space-y-1.5 rounded-lg border p-3 shadow-sm"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <Skeleton className="h-4 w-2/3" />
                                            <Skeleton className="h-5 w-6 rounded-full" />
                                        </div>
                                        <Skeleton className="h-3 w-16" />
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>

                {/* Carrito (apilado en mobile, sticky derecha en desktop) */}
                <div className="border-border bg-card h-fit space-y-3 rounded-lg border p-4 shadow-sm md:sticky md:top-4">
                    <Skeleton className="h-4 w-28" />
                    {/* Toggle tipo de orden */}
                    <div className="space-y-1">
                        <Skeleton className="h-3 w-24" />
                        <div className="grid grid-cols-3 gap-1">
                            <Skeleton className="h-9 w-full" />
                            <Skeleton className="h-9 w-full" />
                            <Skeleton className="h-9 w-full" />
                        </div>
                    </div>
                    {/* Mesa / teléfono */}
                    <div className="space-y-1">
                        <Skeleton className="h-3 w-28" />
                        <Skeleton className="h-9 w-full" />
                    </div>
                    <div className="space-y-1">
                        <Skeleton className="h-3 w-36" />
                        <Skeleton className="h-9 w-full" />
                    </div>
                    {/* Lista de ítems del carrito */}
                    <div className="space-y-2 border-t pt-2">
                        {Array.from({ length: 2 }).map((_, i) => (
                            <div key={i} className="space-y-2 rounded-md border p-2">
                                <div className="flex items-center justify-between">
                                    <Skeleton className="h-4 w-2/3" />
                                    <Skeleton className="h-7 w-20" />
                                </div>
                                <Skeleton className="h-7 w-full" />
                            </div>
                        ))}
                    </div>
                    {/* Total + acción */}
                    <div className="space-y-2 border-t pt-2">
                        <div className="flex items-center justify-between">
                            <Skeleton className="h-4 w-14" />
                            <Skeleton className="h-5 w-20" />
                        </div>
                        <Skeleton className="h-10 w-full" />
                    </div>
                </div>
            </div>
        </div>
    );
}
