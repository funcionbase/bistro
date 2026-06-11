import { type ReactNode } from 'react';

import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { cn } from '@/lib/utils';

interface EditorialEmptyProps {
    /** Pill opcional encima del titulo (ej. "EMPEZAR", "BIENVENIDO"). */
    eyebrow?: string;
    /** Titulo grande (H2). */
    title: string;
    /** Descripcion generosa debajo del titulo. */
    description?: string;
    /** CTA principal (boton, link, formulario corto). */
    action?: ReactNode;
    /** Icono o ilustracion opcional encima del eyebrow (decorativo). */
    icon?: ReactNode;
    /** Si true, oculta el patron de fondo. */
    noPattern?: boolean;
    className?: string;
}

/**
 * Empty state con peso editorial — para paginas vacias por primera vez,
 * modulos recien estrenados, dashboards sin datos todavia.
 *
 * Para empty denso (filtros sin resultados, sub-panel vacio), usar el patron
 * compacto inline. Ver FRONTEND_UI_GUIDELINES.md §10.
 */
export function EditorialEmpty({
    eyebrow,
    title,
    description,
    action,
    icon,
    noPattern = false,
    className,
}: EditorialEmptyProps) {
    return (
        <section
            className={cn(
                'bg-card relative overflow-hidden rounded-3xl border px-6 py-16 text-center md:px-12 md:py-24',
                className,
            )}
        >
            {!noPattern && (
                <PlaceholderPattern className="text-muted-foreground/15 absolute inset-0 size-full" />
            )}
            <div className="relative mx-auto max-w-2xl space-y-5">
                {icon && <div className="text-muted-foreground flex justify-center">{icon}</div>}
                {eyebrow && (
                    <span className="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
                        {eyebrow}
                    </span>
                )}
                <h2 className="font-brand text-foreground text-3xl font-medium leading-[1.05] tracking-[-0.02em] md:text-4xl">
                    {title}
                </h2>
                {description && (
                    <p className="text-muted-foreground text-base md:text-lg">{description}</p>
                )}
                {action && <div className="flex justify-center pt-2">{action}</div>}
            </div>
        </section>
    );
}
