import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface HeroHeadlineProps {
    /**
     * Píldora uppercase encima del H1 (`Bienvenido`, `Registro`, `Tu sesión`).
     * Fondo secondary, tracking-[0.18em] — estética marketing v2.1.
     */
    eyebrow?: string;
    /**
     * Título principal. Acepta JSX para meter `<br />` y partir la frase en
     * varias líneas (típico del lenguaje marketing).
     */
    title: ReactNode;
    /** Subtítulo / descripción debajo del H1. Texto base–lg muted. */
    description?: ReactNode;
    /** Slot inferior — típicamente botones de acción. */
    actions?: ReactNode;
    /**
     * Tamaño del H1:
     *  - `xl` (default): páginas de onboarding/welcome — `text-4xl md:text-5xl
     *    lg:text-6xl`.
     *  - `lg`: paneles secundarios — `text-3xl md:text-4xl lg:text-5xl`.
     */
    size?: 'xl' | 'lg';
    className?: string;
}

/**
 * Bloque tipográfico editorial (eyebrow + H1 font-brand + description) para
 * hero pages — welcome, enrollment, company-selector y similares.
 *
 * Encapsula el patrón "eyebrow chip + headline pesado + bajada" que vivía
 * duplicado en marketing. Usa `font-brand font-medium tracking-[-0.02em]
 * leading-[1.05]` — la firma tipográfica del producto.
 *
 * Coexiste con `PageHeader` (dense/editorial) del tablero: PageHeader es
 * para vistas con datos densos (tablas, KPIs, listings), `HeroHeadline` es
 * para landings y onboarding donde la tipografía manda.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §4 (Tipografía) y §6.2b (catálogo hero).
 */
export function HeroHeadline({ eyebrow, title, description, actions, size = 'xl', className }: HeroHeadlineProps) {
    const isXl = size === 'xl';

    return (
        <div className={cn('space-y-5', className)}>
            {eyebrow && (
                <span className="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
                    {eyebrow}
                </span>
            )}
            <h1
                className={cn(
                    'font-brand font-medium leading-[1.05] tracking-[-0.02em]',
                    isXl ? 'text-4xl md:text-5xl lg:text-6xl' : 'text-3xl md:text-4xl lg:text-5xl',
                )}
            >
                {title}
            </h1>
            {description && (
                <p className="max-w-xl text-base md:text-lg opacity-80">{description}</p>
            )}
            {actions && <div className="pt-2">{actions}</div>}
        </div>
    );
}
