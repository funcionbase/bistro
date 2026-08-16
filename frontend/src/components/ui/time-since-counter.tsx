import { cn } from '@/lib/utils';
import { useEffect, useState } from 'react';

interface TimeSinceCounterProps {
    /** Timestamp ISO desde el cual cuenta minutos transcurridos. */
    sinceIso: string | null;
    /** Umbral de minutos para colorear ámbar. Default 5. */
    warningMinutes?: number;
    /** Umbral de minutos para colorear rojo (urgente). Default 15. */
    dangerMinutes?: number;
    /** Tamaño del chip. */
    size?: 'sm' | 'md';
    className?: string;
}

/**
 * Chip con cronómetro vivo que muestra minutos transcurridos desde
 * `sinceIso`. Útil para el KDS — pinta verde durante los
 * primeros minutos, ámbar al pasar `warningMinutes`, rojo al pasar
 * `dangerMinutes`.
 *
 * Renderea sin reloj del sistema: solo Date.now()/setInterval cada 30s.
 * No incluye el día/fecha, solo cuánto lleva esperando. Si `sinceIso` es
 * null, muestra `—` y queda en tono neutro.
 */
export function TimeSinceCounter({
    sinceIso,
    warningMinutes = 5,
    dangerMinutes = 15,
    size = 'md',
    className,
}: TimeSinceCounterProps) {
    const [, setTick] = useState(0);

    useEffect(() => {
        const id = setInterval(() => setTick((t) => t + 1), 30_000);
        return () => clearInterval(id);
    }, []);

    if (!sinceIso) {
        return (
            <span
                className={cn(
                    'border-border bg-muted text-muted-foreground inline-flex items-center rounded-full border font-medium tabular-nums',
                    size === 'sm' ? 'px-1.5 py-0.5 text-[10px]' : 'px-2 py-0.5 text-xs',
                    className,
                )}
            >
                —
            </span>
        );
    }

    const elapsedMs = Math.max(0, Date.now() - new Date(sinceIso).getTime());
    const minutes = Math.floor(elapsedMs / 60_000);

    let tone = 'border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 text-[color:var(--color-status-safe)]';
    if (minutes >= dangerMinutes) {
        tone = 'border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]';
    } else if (minutes >= warningMinutes) {
        tone = 'border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]';
    }

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border font-semibold tabular-nums',
                size === 'sm' ? 'px-1.5 py-0.5 text-[10px]' : 'px-2 py-0.5 text-xs',
                tone,
                className,
            )}
        >
            {minutes < 1 ? '< 1 min' : `${minutes} min`}
        </span>
    );
}
