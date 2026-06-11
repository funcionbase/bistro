import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface ListCardSkeletonProps {
    /** Cantidad de filas. Default 3. */
    rows?: number;
    /** Cantidad de botones de acción a esqueletizar a la derecha. Default 2. */
    actions?: number;
    /**
     * Variant de layout:
     *  - `row` (default): icono + 2 líneas + botones en una sola fila (densidad alta, escritorio).
     *  - `card`: tarjeta apilada, icono + header + footer con botones. Por defecto va en
     *    columna vertical (`space-y-3`); para grid, pasá `gridClassName`.
     */
    variant?: 'row' | 'card';
    /**
     * Si `true`, los items se muestran como tarjetas en mobile y filas en `sm+`,
     * replicando el patrón de las páginas `/company/branches` y `/company/printers`.
     */
    responsive?: boolean;
    /**
     * Si se pasa, en `variant='card'` el contenedor de las tarjetas usa esta
     * clase en lugar de `space-y-3` (útil para grids como `/company/tables`).
     */
    gridClassName?: string;
    className?: string;
}

/**
 * Esqueleto reutilizable para listados administrativos de empresa:
 * `branches`, `printers`, `tables`, `warehouses`. Replica icono + título +
 * subtítulo + acciones para que la transición a contenido real no provoque
 * layout shift agresivo.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo de primitives) y §13
 * (tokens de skeleton).
 */
export function ListCardSkeleton({
    rows = 3,
    actions = 2,
    variant = 'row',
    responsive = false,
    gridClassName,
    className,
}: ListCardSkeletonProps) {
    if (responsive) {
        return (
            <div className={className} aria-busy="true">
                <CardVariant rows={rows} actions={actions} className="sm:hidden" />
                <RowVariant rows={rows} actions={actions} className="hidden sm:block" />
            </div>
        );
    }

    return (
        <div className={className} aria-busy="true">
            {variant === 'card' ? (
                <CardVariant rows={rows} actions={actions} gridClassName={gridClassName} />
            ) : (
                <RowVariant rows={rows} actions={actions} />
            )}
        </div>
    );
}

function RowVariant({ rows, actions, className }: { rows: number; actions: number; className?: string }) {
    return (
        <ul className={cn('divide-border divide-y', className)}>
            {Array.from({ length: rows }).map((_, i) => (
                <li key={i} className="flex items-center justify-between gap-4 py-3">
                    <div className="flex min-w-0 flex-1 items-center gap-3">
                        <Skeleton className="size-10 shrink-0 rounded-lg" />
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <Skeleton className="h-4 w-36 max-w-full" />
                            <Skeleton className="h-3 w-24 max-w-full" />
                        </div>
                    </div>
                    <div className="flex shrink-0 gap-1.5">
                        {Array.from({ length: actions }).map((_, j) => (
                            <Skeleton key={j} className="h-9 w-16 rounded-md" />
                        ))}
                    </div>
                </li>
            ))}
        </ul>
    );
}

function CardVariant({
    rows,
    actions,
    className,
    gridClassName,
}: {
    rows: number;
    actions: number;
    className?: string;
    gridClassName?: string;
}) {
    return (
        <ul className={cn(gridClassName ?? 'space-y-3', className)}>
            {Array.from({ length: rows }).map((_, i) => (
                <li
                    key={i}
                    className="border-border bg-card space-y-3 rounded-2xl border p-4"
                >
                    <div className="flex items-start gap-3">
                        <Skeleton className="size-10 shrink-0 rounded-lg" />
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <Skeleton className="h-4 w-32 max-w-full" />
                            <Skeleton className="h-3 w-24 max-w-full" />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {Array.from({ length: actions }).map((_, j) => (
                            <Skeleton key={j} className="h-9 w-20 rounded-md" />
                        ))}
                    </div>
                </li>
            ))}
        </ul>
    );
}
