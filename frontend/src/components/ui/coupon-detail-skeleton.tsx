import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface CouponDetailSkeletonProps {
    /** Filas a esqueletizar en la tabla de canjes. Default 5. */
    redemptionRows?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/coupons/{id}` (detalle de cupón).
 *
 * Replica:
 *  - Header con back + título + 3 acciones (editar / activar / eliminar).
 *  - Grid `lg:grid-cols-3`:
 *      · Col 1: tarjeta info (código + status + tipo/valor + 5 InfoRow).
 *      · Cols 2-3: tabla de canjes (RedemptionHistoryTable).
 *  - En mobile (<lg) los dos paneles van apilados full-width.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.4 (grid de cards) y §13 (loading).
 */
export function CouponDetailSkeleton({ redemptionRows = 5, className }: CouponDetailSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando cupón" className={cn('space-y-6', className)}>
            {/* Header */}
            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div className="flex items-center gap-3">
                    <Skeleton className="h-9 w-9 rounded-md" />
                    <Skeleton className="h-8 w-56" />
                </div>
                <div className="flex flex-col gap-2 sm:flex-row md:shrink-0">
                    <Skeleton className="h-9 w-full sm:w-24" />
                    <Skeleton className="h-9 w-full sm:w-28" />
                    <Skeleton className="h-9 w-full sm:w-24" />
                </div>
            </div>

            {/* Body grid */}
            <div className="grid gap-6 lg:grid-cols-3">
                {/* Info card */}
                <div className="lg:col-span-1">
                    <div className="bg-card space-y-5 rounded-lg border p-6 shadow-sm">
                        <div className="flex items-start justify-between gap-3">
                            <div className="space-y-2">
                                <Skeleton className="h-3 w-16" />
                                <Skeleton className="h-7 w-32" />
                            </div>
                            <Skeleton className="h-5 w-20 rounded-full" />
                        </div>
                        <div className="flex items-center gap-2">
                            <Skeleton className="h-5 w-20 rounded-full" />
                            <Skeleton className="h-5 w-16" />
                        </div>
                        <div className="space-y-3 border-t pt-4">
                            {Array.from({ length: 5 }).map((_, i) => (
                                <div key={i} className="flex items-center gap-2">
                                    <Skeleton className="h-4 w-4 rounded" />
                                    <Skeleton className="h-3 w-20" />
                                    <Skeleton className="h-3 w-24" />
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Redemptions table */}
                <div className="lg:col-span-2">
                    <div className="bg-card space-y-3 rounded-lg border p-4 shadow-sm">
                        <Skeleton className="h-5 w-44" />
                        <div className="space-y-2">
                            {Array.from({ length: redemptionRows }).map((_, i) => (
                                <div
                                    key={i}
                                    className="border-border flex items-center justify-between gap-2 border-t pt-2"
                                >
                                    <div className="space-y-1">
                                        <Skeleton className="h-4 w-24" />
                                        <Skeleton className="h-3 w-32" />
                                    </div>
                                    <Skeleton className="h-4 w-16" />
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
