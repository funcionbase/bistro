import { cn } from '@/lib/utils';

/**
 * Lista de barras horizontales con proporcion porcentual sobre un total.
 *
 * Pensado para distribuciones tipo "completadas / canceladas / abandonadas"
 * de un reporte. Los tonos se mapean a tokens del semaforo de estado
 * (`var(--color-status-*)`) para evitar hex hardcoded.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §3 (paleta) y §7 (componentes).
 */
export type DistributionTone = 'safe' | 'warning' | 'critical' | 'primary' | 'muted';

export interface DistributionItem {
    label: string;
    count: number;
    tone?: DistributionTone;
}

interface DistributionBarsProps {
    items: ReadonlyArray<DistributionItem>;
    /**
     * Total absoluto para calcular porcentajes. Si se omite, se toma la suma
     * de `count` de todos los items.
     */
    total?: number;
    /** Heading opcional encima de la lista de barras. */
    title?: string;
    className?: string;
}

const toneClass: Record<DistributionTone, string> = {
    safe: 'bg-[color:var(--color-status-safe)]',
    warning: 'bg-[color:var(--color-status-warning)]',
    critical: 'bg-[color:var(--color-status-critical)]',
    primary: 'bg-primary',
    muted: 'bg-muted-foreground/60',
};

export function DistributionBars({ items, total, title, className }: DistributionBarsProps) {
    const computedTotal = total ?? items.reduce((acc, it) => acc + it.count, 0);
    const safeTotal = computedTotal || 1;

    return (
        <div className={cn('space-y-3', className)}>
            {title && <p className="text-muted-foreground text-sm font-medium">{title}</p>}
            <div className="space-y-2">
                {items.map(({ label, count, tone = 'muted' }) => {
                    const pct = Math.round((count / safeTotal) * 100);
                    return (
                        <div key={label} className="flex items-center gap-3">
                            <span className="text-muted-foreground w-28 shrink-0 text-xs">{label}</span>
                            <div className="bg-muted h-3 flex-1 overflow-hidden rounded-full">
                                <div
                                    className={cn('h-full rounded-full transition-all duration-500', toneClass[tone])}
                                    style={{ width: `${pct}%` }}
                                    aria-label={`${label}: ${pct}%`}
                                />
                            </div>
                            <span className="w-10 shrink-0 text-right text-xs font-medium tabular-nums">{count}</span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
