import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface BillingSubscriptionSkeletonProps {
    className?: string;
}

/**
 * Esqueleto de la card "Suscripción activa" (`/billing`).
 *
 * Replica la disposición real: 4 stats (Plan, Precio, Activa desde,
 * Estado cuenta) en grid 2×2 a mobile, fila horizontal a sm+.
 */
export function BillingSubscriptionSkeleton({ className }: BillingSubscriptionSkeletonProps) {
    return (
        <Card className={cn('overflow-hidden', className)} aria-busy="true">
            <CardHeader>
                <Skeleton className="h-5 w-40" />
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-2 gap-4 sm:flex sm:flex-wrap sm:gap-8">
                    {[0, 1, 2, 3].map((i) => (
                        <div key={i} className="space-y-1.5">
                            <Skeleton className="h-3 w-20" />
                            <Skeleton className="h-5 w-28" />
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

interface BillingInvoicesSkeletonProps {
    /**
     * Cantidad de filas a esqueletizar (default 5). Mobile renderiza
     * cards; desktop renderiza filas de tabla. Ambos se muestran al
     * mismo tiempo y se alternan con `sm:hidden` / `hidden sm:block`.
     */
    rows?: number;
    className?: string;
}

/**
 * Esqueleto del historial de facturas (`/billing`).
 *
 * Replica la doble variante real:
 *  - Mobile: card-stack con título + subtítulo + 4 fields + footer (PDF).
 *  - Desktop: tabla densa con 8 columnas (Tipo / Período / Base /
 *    Descuento / Total / Vencimiento / Estado / PDF).
 */
export function BillingInvoicesSkeleton({ rows = 5, className }: BillingInvoicesSkeletonProps) {
    return (
        <Card className={cn('overflow-hidden', className)} aria-busy="true">
            <CardHeader>
                <Skeleton className="h-5 w-44" />
            </CardHeader>
            <CardContent>
                {/* Mobile cards */}
                <ul className="space-y-3 sm:hidden">
                    {Array.from({ length: rows }).map((_, i) => (
                        <li
                            key={i}
                            className="border-border bg-card space-y-3 rounded-2xl border p-4"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0 space-y-1.5">
                                    <Skeleton className="h-4 w-32" />
                                    <Skeleton className="h-3 w-20" />
                                </div>
                                <Skeleton className="h-5 w-16 rounded-full" />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                {[0, 1, 2, 3].map((j) => (
                                    <div key={j} className="space-y-1">
                                        <Skeleton className="h-2.5 w-14" />
                                        <Skeleton className="h-3.5 w-20" />
                                    </div>
                                ))}
                            </div>
                            <div className="flex justify-end">
                                <Skeleton className="h-9 w-20 rounded-md" />
                            </div>
                        </li>
                    ))}
                </ul>

                {/* Desktop table */}
                <div className="hidden sm:block">
                    <div className="border-border space-y-2 border-b pb-3">
                        <Skeleton className="h-4 w-full max-w-3xl" />
                    </div>
                    <ul className="space-y-2 pt-3">
                        {Array.from({ length: rows }).map((_, i) => (
                            <li key={i} className="flex items-center gap-3">
                                <Skeleton className="h-4 w-16 rounded-full" />
                                <Skeleton className="h-4 flex-1 max-w-32" />
                                <Skeleton className="h-4 w-16" />
                                <Skeleton className="h-4 w-16" />
                                <Skeleton className="h-4 w-20" />
                                <Skeleton className="h-4 w-20" />
                                <Skeleton className="h-4 w-16 rounded-full" />
                                <Skeleton className="h-7 w-7 rounded-md" />
                            </li>
                        ))}
                    </ul>
                </div>
            </CardContent>
        </Card>
    );
}
