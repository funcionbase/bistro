import { type ReactNode } from 'react';

import { ActiveBranchBadge } from '@/components/ui/active-branch-badge';
import { cn } from '@/lib/utils';

interface PageHeaderProps {
    title: ReactNode;
    description?: string;
    actions?: ReactNode;
    /**
     * Etiqueta pill uppercase que aparece sobre el H1.
     * Patron "eyebrow" del marketing v2.1 (ej. "COLABORADORES", "REPORTES").
     */
    eyebrow?: string;
    /**
     * Tono del eyebrow:
     *  - 'neutral' (default): bg-secondary / texto neutro.
     *  - 'accent': bg-accent (lime) — momentos de logro / bienvenida.
     */
    eyebrowTone?: 'neutral' | 'accent';
    /**
     * Modo de presentacion:
     *  - 'dense' (default): listings, forms, tablas. H1 text-2xl md:text-3xl.
     *  - 'editorial': dashboard hero, welcome, onboarding, reportes con peso.
     *    H1 text-3xl md:text-4xl lg:text-5xl, mas aire alrededor.
     *
     * Ver FRONTEND_UI_GUIDELINES.md §4 (Tipografia) y §5 (Spacing dual).
     */
    variant?: 'dense' | 'editorial';
    className?: string;
    /**
     * Aislamiento por sede (#192 Fase 3.3): renderizar el pill de sede
     * activa a la derecha del eyebrow. El badge se autocompleta con
     * `useActiveBranch()` y se oculta si el usuario tiene ≤1 sede.
     * Default `true`. Páginas globales (settings, billing, legales)
     * pueden pasarlo en `false` si el contexto de sede no aplica.
     */
    showBranchBadge?: boolean;
}

const eyebrowToneClasses: Record<NonNullable<PageHeaderProps['eyebrowTone']>, string> = {
    neutral: 'bg-secondary text-secondary-foreground',
    accent: 'bg-accent text-accent-foreground',
};

export function PageHeader({
    title,
    description,
    actions,
    eyebrow,
    eyebrowTone = 'neutral',
    variant = 'dense',
    className,
    showBranchBadge = true,
}: PageHeaderProps) {
    const isEditorial = variant === 'editorial';

    return (
        <div
            className={cn(
                'flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between',
                isEditorial && 'gap-6 sm:gap-8',
                className,
            )}
        >
            <div className={cn('min-w-0 md:grow md:basis-80', isEditorial && 'space-y-4')}>
                {(eyebrow || showBranchBadge) && (
                    <div className="flex flex-wrap items-center gap-2">
                        {eyebrow && (
                            <span
                                className={cn(
                                    'inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]',
                                    eyebrowToneClasses[eyebrowTone],
                                )}
                            >
                                {eyebrow}
                            </span>
                        )}
                        {showBranchBadge && <ActiveBranchBadge />}
                    </div>
                )}
                <h1
                    className={cn(
                        'font-brand text-foreground',
                        isEditorial
                            ? 'text-3xl md:text-4xl lg:text-5xl font-medium tracking-[-0.02em] leading-[1.05]'
                            : 'text-2xl md:text-3xl font-medium tracking-tight',
                    )}
                >
                    {title}
                </h1>
                {description && (
                    <p
                        className={cn(
                            'text-muted-foreground',
                            isEditorial ? 'text-base md:text-lg max-w-2xl' : 'text-sm',
                        )}
                    >
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:shrink [&>*]:w-full sm:[&>*]:w-auto">
                    {actions}
                </div>
            )}
        </div>
    );
}
