import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface MenuDetailSkeletonProps {
    /** Cantidad de categorías a esqueletizar en panel izquierdo. Default 4. */
    categories?: number;
    /** Cantidad de items a esqueletizar en panel derecho. Default 4. */
    items?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/menu/{id}` (editor de menú con dos paneles).
 *
 * Replica:
 *  - Header sticky (back + título + descripción).
 *  - Barra de acciones (Agregar categoría / Vista previa / Publicar).
 *  - Mobile (<md): un solo panel apilado (categorías arriba, items abajo).
 *  - Desktop (≥md): grid 2 cols con categorías a la izquierda y items a la
 *    derecha.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.4 (grid de cards) y §13 (loading).
 */
export function MenuDetailSkeleton({ categories = 4, items = 4, className }: MenuDetailSkeletonProps) {
    return (
        <div
            aria-busy="true"
            aria-label="Cargando menú"
            className={cn('flex h-full flex-col', className)}
        >
            {/* Header */}
            <div className="flex items-center gap-3 border-b px-4 py-4 sm:px-6">
                <Skeleton className="h-9 w-9 rounded-md" />
                <div className="min-w-0 flex-1 space-y-1.5">
                    <Skeleton className="h-5 w-40" />
                    <Skeleton className="h-3 w-56" />
                </div>
            </div>

            <div className="flex-1 space-y-4 p-4 sm:p-6">
                {/* Action buttons */}
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <Skeleton className="h-9 w-full rounded-md sm:w-44" />
                    <Skeleton className="h-9 w-full rounded-md sm:w-32" />
                    <Skeleton className="h-9 w-full rounded-md sm:ml-auto sm:w-32" />
                </div>

                {/* Panels: stack en mobile, grid en md */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {/* Categorías */}
                    <div className="space-y-3">
                        <Skeleton className="h-4 w-28" />
                        <div className="space-y-2">
                            {Array.from({ length: categories }).map((_, i) => (
                                <div
                                    key={i}
                                    className="border-border bg-card flex items-center gap-3 rounded-lg border p-3 shadow-sm"
                                >
                                    <Skeleton className="h-4 w-4 rounded" />
                                    <Skeleton className="h-4 flex-1" />
                                    <Skeleton className="h-7 w-7 rounded-md" />
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Items */}
                    <div className="space-y-3">
                        <Skeleton className="h-4 w-40" />
                        <div className="space-y-2">
                            {Array.from({ length: items }).map((_, i) => (
                                <div
                                    key={i}
                                    className="border-border bg-card flex items-center gap-3 rounded-lg border p-3 shadow-sm"
                                >
                                    <Skeleton className="h-12 w-12 shrink-0 rounded-md" />
                                    <div className="min-w-0 flex-1 space-y-1.5">
                                        <Skeleton className="h-4 w-3/4" />
                                        <Skeleton className="h-3 w-1/2" />
                                    </div>
                                    <Skeleton className="h-4 w-14" />
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
