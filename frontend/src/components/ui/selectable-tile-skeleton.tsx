import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface SelectableTileSkeletonProps {
    /**
     * Si `true`, muestra una pildora extra abajo del tile (estado/badge).
     * Default `true` — replica el caso `auth/company-selector` con badge de
     * estado y `auth/branch-selector` sin badge.
     */
    withBadge?: boolean;
    /** Lineas de texto secundarias bajo el titulo principal. Default 2. */
    secondaryLines?: number;
    className?: string;
}

/**
 * Skeleton del `SelectableTile` — replica avatar/icon 56x56 + titulo + N
 * lineas auxiliares + (opcional) badge inferior.
 *
 * Pensado para los selectores `auth/company-selector` y `auth/branch-selector`
 * en conexiones lentas mientras llega el JWT/sedes.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §10 (skeletons) y §13 (loading).
 */
export function SelectableTileSkeleton({
    withBadge = true,
    secondaryLines = 2,
    className,
}: SelectableTileSkeletonProps) {
    return (
        <div
            aria-busy="true"
            aria-label="Cargando opcion"
            className={cn(
                'bg-card border-border flex w-full flex-col items-center gap-4 rounded-xl border p-6 shadow-sm',
                className,
            )}
        >
            <Skeleton className="h-14 w-14 rounded-xl" />
            <div className="w-full space-y-1.5">
                <Skeleton className="mx-auto h-4 w-3/4" />
                {Array.from({ length: secondaryLines }).map((_, i) => (
                    <Skeleton key={i} className="mx-auto h-3 w-1/2" />
                ))}
            </div>
            {withBadge && <Skeleton className="h-5 w-20 rounded-full" />}
        </div>
    );
}

interface SelectableTileGridSkeletonProps {
    /** Numero de tiles a esqueletizar (default 4). */
    tiles?: number;
    /** Clases de grid; default `gap-4 sm:grid-cols-2`. */
    gridClassName?: string;
    /** Forwarded a cada tile. */
    withBadge?: boolean;
    /** Forwarded a cada tile. */
    secondaryLines?: number;
    className?: string;
}

/**
 * Grid de `SelectableTileSkeleton` — wrapper utilitario para listas.
 */
export function SelectableTileGridSkeleton({
    tiles = 4,
    gridClassName = 'gap-4 sm:grid-cols-2',
    withBadge = true,
    secondaryLines = 2,
    className,
}: SelectableTileGridSkeletonProps) {
    return (
        <div className={cn('grid', gridClassName, className)}>
            {Array.from({ length: tiles }).map((_, i) => (
                <SelectableTileSkeleton key={i} withBadge={withBadge} secondaryLines={secondaryLines} />
            ))}
        </div>
    );
}
