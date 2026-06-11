import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import type { AvailableDeliverer } from '@/types';

interface DelivererPickerListProps {
    deliverers: AvailableDeliverer[];
    /** ID seleccionado actualmente (controlado por el padre). */
    selectedId: string | null;
    onSelect: (id: string) => void;
    loading?: boolean;
    /** ID a excluir de la lista (ej. repartidor actual en reasignacion). */
    excludeId?: string;
    /** Mensaje cuando la lista filtrada esta vacia. */
    emptyMessage?: string;
    /** Numero de filas de skeleton durante loading. Default 3. */
    skeletonRows?: number;
    disabled?: boolean;
    className?: string;
}

/**
 * Lista de repartidores seleccionable para flujos de asignacion/reasignacion.
 *
 * Cada repartidor se renderiza como `<button>` con touch-target >= 44px,
 * resaltando el activo con tokens `primary`. Muestra contadores
 * `active_deliveries_count` y `daily_completed_count` para que el coordinador
 * decida basado en carga actual.
 *
 * Acepta `excludeId` para filtrar al repartidor actual en flujos de
 * reasignacion (no tendria sentido reasignarle a si mismo).
 */
export function DelivererPickerList({
    deliverers,
    selectedId,
    onSelect,
    loading = false,
    excludeId,
    emptyMessage = 'No hay repartidores disponibles.',
    skeletonRows = 3,
    disabled = false,
    className,
}: DelivererPickerListProps) {
    if (loading) {
        return (
            <div className={cn('space-y-2', className)}>
                {Array.from({ length: skeletonRows }).map((_, i) => (
                    <Skeleton key={i} className="h-12 w-full" />
                ))}
            </div>
        );
    }

    const filtered = excludeId !== undefined ? deliverers.filter((d) => d.id !== excludeId) : deliverers;

    if (filtered.length === 0) {
        return <p className="text-muted-foreground text-sm">{emptyMessage}</p>;
    }

    return (
        <div className={cn('space-y-2', className)} role="radiogroup" aria-label="Repartidores disponibles">
            {filtered.map((d) => {
                const active = selectedId === d.id;
                return (
                    <button
                        key={d.id}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onSelect(d.id)}
                        disabled={disabled}
                        className={cn(
                            'flex min-h-[44px] w-full items-center justify-between rounded-lg border px-3 py-2 text-left text-sm transition',
                            'focus:ring-ring focus:ring-2 focus:outline-none',
                            'disabled:cursor-not-allowed disabled:opacity-50',
                            active ? 'border-primary bg-primary/10' : 'border-border hover:bg-muted',
                        )}
                    >
                        <span className="font-medium">{d.name}</span>
                        <span className="text-muted-foreground text-xs tabular-nums">
                            {d.active_deliveries_count} activas · {d.daily_completed_count} hoy
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
