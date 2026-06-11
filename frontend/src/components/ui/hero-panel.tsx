import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

type HeroPanelTone = 'accent' | 'foreground' | 'card';

interface HeroPanelProps {
    /**
     * Píldora uppercase en la esquina superior (`Diseñado para tu empresa`,
     * `Acceso seguro`, `Pendiente de verificación`). Usa fondo invertido para
     * destacar sobre el tone del panel.
     */
    eyebrow?: string;
    /** Contenido principal del panel. */
    children: ReactNode;
    /** Footer opcional — típicamente un párrafo descriptivo o lista de checks. */
    footer?: ReactNode;
    /**
     * Tone del panel:
     *  - `accent` (default): bloque lime — para hero panels marketing/onboarding.
     *  - `foreground`: bloque negro — para alertas hero importantes.
     *  - `card`: fondo neutro — fallback para previews/showcases.
     */
    tone?: HeroPanelTone;
    /** Padding más compacto si true. Default usa el padding generoso editorial. */
    compact?: boolean;
    className?: string;
}

const toneClasses: Record<HeroPanelTone, string> = {
    accent: 'bg-accent text-accent-foreground',
    foreground: 'bg-foreground text-background',
    card: 'bg-card text-card-foreground border-border border',
};

/**
 * Panel hero editorial — bloque grande `rounded-3xl` con eyebrow + slot + footer.
 *
 * Usado en las páginas de marketing/onboarding (welcome, enrollment,
 * company-selector) y en el `KpiHero` del dashboard. Encapsula el patrón
 * lime+eyebrow que vivía duplicado en cada hero.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2b (catálogo hero) — coexiste con
 * `DashboardPanel` (rounded-2xl, tabular, denso) sin remplazarlo. Hero panel
 * = marketing / hero estático. DashboardPanel = data del día a día.
 */
export function HeroPanel({
    eyebrow,
    children,
    footer,
    tone = 'accent',
    compact = false,
    className,
}: HeroPanelProps) {
    return (
        <aside
            className={cn(
                'flex flex-col justify-between gap-8 rounded-3xl',
                compact ? 'p-5 md:p-6' : 'p-6 md:p-8 lg:p-10',
                toneClasses[tone],
                className,
            )}
        >
            {eyebrow && (
                <span className="bg-foreground text-background inline-flex w-fit items-center rounded-full px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em]">
                    {eyebrow}
                </span>
            )}
            {children}
            {footer}
        </aside>
    );
}

interface HeroPanelStatsProps {
    /**
     * Lista de stats grandes. Recomendado 1–3 items para no romper la
     * jerarquía visual. Cada `value` se renderiza con `font-brand
     * tabular-nums` en tamaño 2xl–3xl.
     */
    stats: Array<{ label: string; value: ReactNode }>;
    /**
     * Layout — `column` (default, stack vertical con divisores) para hero
     * pages, `grid` (3 columnas) para KpiHero del dashboard.
     */
    layout?: 'column' | 'grid';
    className?: string;
}

/**
 * Lista de stats grandes para `HeroPanel`. Cada stat va con label uppercase
 * tracking-[0.15em] arriba y valor grande `font-brand tabular-nums` abajo.
 *
 * En layout `column` los items se separan con líneas `border-foreground/10`
 * sutiles. En layout `grid` se distribuyen horizontalmente sin divisores.
 */
export function HeroPanelStats({ stats, layout = 'column', className }: HeroPanelStatsProps) {
    if (layout === 'grid') {
        return (
            <dl
                className={cn(
                    'grid gap-4',
                    stats.length === 1 && 'grid-cols-1',
                    stats.length === 2 && 'grid-cols-2',
                    stats.length >= 3 && 'grid-cols-3',
                    className,
                )}
            >
                {stats.map((stat) => (
                    <div key={stat.label} className="min-w-0">
                        <dt className="text-[11px] uppercase tracking-[0.15em] opacity-70">{stat.label}</dt>
                        <dd className="font-brand mt-1 truncate text-3xl tabular-nums md:text-4xl">{stat.value}</dd>
                    </div>
                ))}
            </dl>
        );
    }

    return (
        <dl className={cn('grid grid-cols-1 gap-6', className)}>
            {stats.map((stat) => (
                <div key={stat.label} className="border-foreground/10 border-b pb-4 last:border-b-0 last:pb-0">
                    <dt className="text-[11px] uppercase tracking-[0.15em] opacity-70">{stat.label}</dt>
                    <dd className="font-brand mt-1 text-2xl tabular-nums md:text-3xl">{stat.value}</dd>
                </div>
            ))}
        </dl>
    );
}
