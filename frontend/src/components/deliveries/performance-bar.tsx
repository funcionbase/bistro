import { cn } from '@/lib/utils';

interface PerformanceBarProps {
    percentage: number;
    label?: string;
    className?: string;
}

/**
 * Barra horizontal de rendimiento relativo (0-100%).
 *
 * Mapea el porcentaje al token de estado del semáforo:
 *  - ≥90 → safe (verde tokenizado)
 *  - ≥70 → warning (amarillo tokenizado)
 *  - <70 → critical (rojo tokenizado)
 *
 * Ver FRONTEND_UI_GUIDELINES.md §3 (paleta) y §7 (componentes).
 */
function toneClass(pct: number): string {
    if (pct >= 90) return 'bg-[color:var(--color-status-safe)]';
    if (pct >= 70) return 'bg-[color:var(--color-status-warning)]';
    return 'bg-[color:var(--color-status-critical)]';
}

export function PerformanceBar({ percentage, label, className }: PerformanceBarProps) {
    const clamped = Math.min(100, Math.max(0, percentage));

    return (
        <div className={cn('flex items-center gap-2', className)}>
            <div className="bg-muted h-2 w-24 overflow-hidden rounded-full">
                <div
                    className={cn('h-full rounded-full transition-all duration-500 ease-out', toneClass(clamped))}
                    style={{ width: `${clamped}%` }}
                    aria-label={`${clamped}%`}
                />
            </div>
            <span className="text-muted-foreground text-xs font-medium tabular-nums">{label ?? `${clamped}%`}</span>
        </div>
    );
}
