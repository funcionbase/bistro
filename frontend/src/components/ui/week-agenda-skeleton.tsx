import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface WeekAgendaSkeletonProps {
    className?: string;
}

/**
 * Skeleton fiel para `/me/agenda`. Renderiza 7 cards (una por día) con la
 * silueta de header + 2 slots de turno. En mobile las 7 cards quedan apiladas
 * verticalmente; en `md+` van en grid de 7 columnas como el real.
 */
export function WeekAgendaSkeleton({ className }: WeekAgendaSkeletonProps) {
    return (
        <div className={cn('grid gap-3 md:grid-cols-7', className)} aria-busy="true" aria-label="Cargando agenda">
            {Array.from({ length: 7 }).map((_, i) => (
                <div
                    key={i}
                    className="border-border bg-card space-y-2 rounded-lg border p-3"
                >
                    <Skeleton className="h-3 w-16" />
                    <div className="space-y-2 pt-1">
                        <Skeleton className="h-12 w-full rounded-md" />
                        {i % 3 === 0 && <Skeleton className="h-12 w-full rounded-md" />}
                    </div>
                </div>
            ))}
        </div>
    );
}
