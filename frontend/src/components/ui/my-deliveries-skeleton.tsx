import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface MyDeliveriesSkeletonProps {
    /** Cards a esqueletizar dentro de la tab activa. Default 3. */
    cards?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/my-deliveries` (vista del domiciliario).
 *
 * Replica:
 *  - PageHeader (eyebrow Domicilios + título + descripción).
 *  - TabsList (3 tabs: Asignadas / Disponibles / Historial).
 *  - Lista vertical de MyDeliveryCards.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §13 (loading).
 */
export function MyDeliveriesSkeleton({ cards = 3, className }: MyDeliveriesSkeletonProps) {
    return (
        <div
            aria-busy="true"
            aria-label="Cargando mis entregas"
            className={cn('flex flex-col gap-4', className)}
        >
            {/* PageHeader */}
            <div className="space-y-2">
                <Skeleton className="h-4 w-24 rounded-full" />
                <Skeleton className="h-8 w-44" />
                <Skeleton className="h-3.5 w-full max-w-md" />
            </div>

            {/* TabsList */}
            <div className="bg-muted/30 grid grid-cols-3 gap-1 rounded-md p-1 sm:flex sm:w-auto">
                <Skeleton className="h-9 w-full sm:w-32" />
                <Skeleton className="h-9 w-full sm:w-32" />
                <Skeleton className="h-9 w-full sm:w-32" />
            </div>

            {/* Cards list */}
            <div className="space-y-3">
                {Array.from({ length: cards }).map((_, i) => (
                    <div
                        key={i}
                        className="border-border bg-card space-y-3 rounded-xl border p-4 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="space-y-1.5">
                                <Skeleton className="h-5 w-32" />
                                <Skeleton className="h-3 w-24" />
                            </div>
                            <Skeleton className="h-5 w-20 rounded-full" />
                        </div>
                        <div className="space-y-1.5">
                            <Skeleton className="h-3.5 w-full" />
                            <Skeleton className="h-3.5 w-2/3" />
                        </div>
                        <div className="border-border flex items-center justify-between border-t pt-2">
                            <Skeleton className="h-3 w-20" />
                            <Skeleton className="h-4 w-16" />
                        </div>
                        <div className="flex gap-2">
                            <Skeleton className="h-10 flex-1" />
                            <Skeleton className="h-10 flex-1" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
