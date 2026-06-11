import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface ClientDetailSkeletonProps {
    /** Cantidad de KpiCells a esqueletizar. Default 8. */
    kpis?: number;
    /** Cantidad de filas en la lista de órdenes. Default 4. */
    orders?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/clients/{phone}` (perfil de cliente).
 *
 * Replica:
 *  - Back button + card-header con nombre + teléfono + segmento + acción
 *    (Ver chat).
 *  - 8 KpiCell en grid 2 / 4 cols (órdenes totales, gastado, ticket, etc.).
 *  - Sección Etiquetas con 3 pills.
 *  - Loyalty panel (si permiso).
 *  - Tabs (Historial / Chats / Notas) + lista de órdenes apilada.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §5 (spacing) y §13 (loading).
 */
export function ClientDetailSkeleton({ kpis = 8, orders = 4, className }: ClientDetailSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando cliente" className={cn('space-y-6', className)}>
            {/* Back */}
            <div className="flex items-center gap-2">
                <Skeleton className="h-8 w-24 rounded-md" />
            </div>

            {/* Header card */}
            <div className="bg-card space-y-4 rounded-lg border p-5 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-2">
                        <Skeleton className="h-8 w-56" />
                        <div className="flex flex-wrap items-center gap-2">
                            <Skeleton className="h-3.5 w-3.5 rounded" />
                            <Skeleton className="h-4 w-32" />
                            <Skeleton className="h-5 w-20 rounded-full" />
                        </div>
                    </div>
                    <Skeleton className="h-9 w-full rounded-md sm:w-32" />
                </div>

                {/* KPIs */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    {Array.from({ length: kpis }).map((_, i) => (
                        <div
                            key={i}
                            className="border-border bg-card space-y-1.5 rounded-lg border p-4 shadow-sm"
                        >
                            <Skeleton className="h-3 w-24" />
                            <Skeleton className="h-6 w-20" />
                        </div>
                    ))}
                </div>

                {/* Tags */}
                <div className="space-y-2">
                    <Skeleton className="h-3 w-20" />
                    <div className="flex flex-wrap gap-1.5">
                        <Skeleton className="h-6 w-16 rounded-full" />
                        <Skeleton className="h-6 w-20 rounded-full" />
                        <Skeleton className="h-6 w-14 rounded-full" />
                    </div>
                </div>
            </div>

            {/* Tabs */}
            <div className="space-y-3">
                <div className="flex flex-wrap gap-2">
                    <Skeleton className="h-9 w-32 rounded-md" />
                    <Skeleton className="h-9 w-24 rounded-md" />
                    <Skeleton className="h-9 w-32 rounded-md" />
                </div>
                <ul className="bg-card divide-y rounded-lg border shadow-sm">
                    {Array.from({ length: orders }).map((_, i) => (
                        <li
                            key={i}
                            className="flex flex-col gap-2 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div className="flex flex-wrap items-center gap-3">
                                <Skeleton className="h-5 w-12 rounded" />
                                <Skeleton className="h-5 w-20 rounded-full" />
                                <Skeleton className="h-3 w-24" />
                                <Skeleton className="h-3 w-20" />
                            </div>
                            <Skeleton className="h-4 w-20 sm:ml-auto" />
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
