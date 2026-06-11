import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface KanbanBoardSkeletonProps {
    /** Tarjetas a esqueletizar por columna en desktop. Default 3. */
    cardsPerColumn?: number;
    /** Tarjetas a esqueletizar en la única columna mobile. Default 4. */
    mobileCards?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/orders/board` (tablero kanban).
 *
 * Replica:
 *  - PageHeader (eyebrow Órdenes + título + LiveIndicator).
 *  - Mobile (<md): selector de columna + lista vertical con N OrderCards.
 *  - Desktop (≥md): 5 columnas horizontales con header tonal y N cards cada una.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.4 (grids) y §13 (loading).
 */
export function KanbanBoardSkeleton({
    cardsPerColumn = 3,
    mobileCards = 4,
    className,
}: KanbanBoardSkeletonProps) {
    const columnTones = [
        'bg-secondary',
        'bg-[color:var(--color-status-warning)]/15',
        'bg-primary/15',
        'bg-primary/25',
        'bg-[color:var(--color-status-safe)]/15',
    ];

    return (
        <div aria-busy="true" aria-label="Cargando tablero" className={cn('space-y-4', className)}>
            {/* PageHeader */}
            <div className="px-4 pt-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-4 w-20 rounded-full" />
                    <Skeleton className="h-8 w-32" />
                    <Skeleton className="h-3.5 w-full max-w-md" />
                </div>
                <Skeleton className="h-6 w-28 rounded-full" />
            </div>

            {/* Mobile: select + lista */}
            <div className="md:hidden space-y-3 p-4">
                <Skeleton className="h-11 w-full" />
                <div className={cn('flex items-center justify-between rounded-t-lg px-3 py-2', columnTones[0])}>
                    <Skeleton className="h-4 w-24" />
                    <Skeleton className="h-5 w-7 rounded-full" />
                </div>
                <div className="space-y-3">
                    {Array.from({ length: mobileCards }).map((_, i) => (
                        <BoardCardSkeleton key={i} />
                    ))}
                </div>
            </div>

            {/* Desktop: 5 columnas */}
            <div className="hidden md:flex h-[calc(100dvh-12rem)] gap-3 overflow-x-auto p-3 sm:gap-4 sm:p-4 lg:h-[80vh]">
                {columnTones.map((tone, i) => (
                    <div key={i} className="flex min-w-[220px] flex-1 flex-col">
                        <div className={cn('flex items-center justify-between rounded-t-lg px-3 py-2', tone)}>
                            <Skeleton className="h-4 w-20" />
                            <Skeleton className="h-5 w-7 rounded-full" />
                        </div>
                        <div className="bg-muted/30 flex-1 space-y-2 rounded-b-lg p-2">
                            {Array.from({ length: cardsPerColumn }).map((_, j) => (
                                <BoardCardSkeleton key={j} />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function BoardCardSkeleton() {
    return (
        <div className="border-border bg-card space-y-2 rounded-lg border p-3 shadow-sm">
            <div className="flex items-center justify-between">
                <Skeleton className="h-3.5 w-20" />
                <Skeleton className="h-3 w-16" />
            </div>
            <Skeleton className="h-5 w-24 rounded-full" />
            <div className="flex items-center justify-between">
                <Skeleton className="h-3 w-16" />
                <Skeleton className="h-4 w-14 rounded-full" />
            </div>
            <Skeleton className="h-3 w-12" />
        </div>
    );
}
