import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface MenusListSkeletonProps {
    /** Cantidad de tarjetas de menú a esqueletizar. Default 6. */
    cards?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/menu` (gestión de menús de la empresa).
 *
 * Replica:
 *  - PageHeader (eyebrow + título + descripción + 2 acciones).
 *  - Grid 1/2/3 cols de `MenuCard`: nombre + badge estado + categorías
 *    + actualizado + barra de 3-4 botones (Ver, Publicar, Programar,
 *    Duplicar).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo de cards) y §13 (loading).
 */
export function MenusListSkeleton({ cards = 6, className }: MenusListSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando menús" className={cn('space-y-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-20 rounded-full" />
                    <Skeleton className="h-9 w-64" />
                    <Skeleton className="h-4 w-full max-w-md" />
                </div>
                <div className="flex flex-col gap-2 sm:flex-row md:shrink-0">
                    <Skeleton className="h-9 w-full sm:w-40" />
                    <Skeleton className="h-9 w-full sm:w-32" />
                </div>
            </div>

            {/* Grid de menu-cards */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {Array.from({ length: cards }).map((_, i) => (
                    <div
                        key={i}
                        className="border-border bg-card space-y-3 rounded-lg border p-4 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0 flex-1 space-y-1.5">
                                <Skeleton className="h-4 w-3/4" />
                                <Skeleton className="h-3 w-2/3" />
                            </div>
                            <Skeleton className="h-5 w-16 shrink-0 rounded-full" />
                        </div>
                        <Skeleton className="h-3 w-24" />
                        <div className="flex flex-wrap gap-1">
                            <Skeleton className="h-5 w-10 rounded-full" />
                            <Skeleton className="h-5 w-10 rounded-full" />
                            <Skeleton className="h-5 w-10 rounded-full" />
                        </div>
                        <Skeleton className="h-3 w-32" />
                        <div className="flex flex-wrap gap-2 pt-1">
                            <Skeleton className="h-8 w-24 rounded-md" />
                            <Skeleton className="h-8 w-24 rounded-md" />
                            <Skeleton className="h-8 w-9 rounded-md" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
