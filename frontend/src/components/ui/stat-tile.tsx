import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type StatTileTone = 'default' | 'safe' | 'warning' | 'critical' | 'primary' | 'accent';

interface StatTileProps {
    /** Valor principal (número, monto, duración, "—" si no aplica). */
    value: ReactNode;
    /** Etiqueta corta debajo del valor. */
    label: string;
    /** Tono del fondo/texto. Default: muted neutro. */
    tone?: StatTileTone;
    /** Tamaño del valor — 'md' default para grids 3-up, 'lg' para hero stats. */
    size?: 'md' | 'lg';
    className?: string;
}

const toneClasses: Record<StatTileTone, { bg: string; value: string }> = {
    default: { bg: 'bg-muted', value: '' },
    safe: {
        bg: 'bg-[color:var(--color-status-safe)]/10',
        value: 'text-[color:var(--color-status-safe)]',
    },
    warning: {
        bg: 'bg-[color:var(--color-status-warning)]/10',
        value: 'text-[color:var(--color-status-warning)]',
    },
    critical: {
        bg: 'bg-[color:var(--color-status-critical)]/10',
        value: 'text-[color:var(--color-status-critical)]',
    },
    primary: { bg: 'bg-primary/10', value: 'text-primary' },
    accent: { bg: 'bg-accent text-accent-foreground', value: '' },
};

const sizeClasses: Record<NonNullable<StatTileProps['size']>, { wrapper: string; value: string; label: string }> = {
    md: { wrapper: 'p-3', value: 'text-base md:text-xl', label: 'text-xs' },
    lg: { wrapper: 'p-4 md:p-5', value: 'text-2xl md:text-3xl', label: 'text-xs md:text-sm' },
};

/**
 * Mini-card de stat para grids 3-up dentro de paneles del dashboard.
 *
 * Valor numérico grande arriba, label corto abajo, fondo según tone.
 * Para tiles de bienvenida/hero usar `KpiHero` en su lugar (ver §6.2b).
 * Para detalle estilo "label arriba / value abajo" usar `KpiCell`.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §7.
 */
export function StatTile({ value, label, tone = 'default', size = 'md', className }: StatTileProps) {
    const t = toneClasses[tone];
    const s = sizeClasses[size];

    return (
        <div className={cn('min-w-0 rounded-xl text-center', t.bg, s.wrapper, className)}>
            <p className={cn('font-mono font-semibold tabular-nums break-words', s.value, t.value)}>{value}</p>
            <p className="text-muted-foreground truncate">
                <span className={cn('uppercase tracking-[0.15em]', s.label)}>{label}</span>
            </p>
        </div>
    );
}
