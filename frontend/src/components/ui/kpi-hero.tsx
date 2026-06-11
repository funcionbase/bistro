import { type ReactNode } from 'react';

import { HeroHeadline } from '@/components/ui/hero-headline';
import { HeroPanel, HeroPanelStats } from '@/components/ui/hero-panel';
import { cn } from '@/lib/utils';

export interface KpiHeroStat {
    label: string;
    value: ReactNode;
}

interface KpiHeroProps {
    /** Pill uppercase encima del H1 (ej. "RESUMEN DEL DIA"). */
    eyebrow?: string;
    /** Titulo principal (H1). */
    title: string;
    /** Descripcion opcional debajo del H1. */
    description?: string;
    /** Slot de acciones (botones, links) debajo del H1. */
    actions?: ReactNode;
    /**
     * Stats que se muestran en el bloque lime a la derecha.
     * Maximo 3 — si necesitas mas, considera una grid de KpiCard estandar.
     */
    stats: KpiHeroStat[];
    /** Pill pequeno dentro del bloque lime (ej. "EN VIVO", "HOY"). */
    statsEyebrow?: string;
    className?: string;
}

/**
 * Hero con stats al estilo del landing v2.1 — grid 8/4 con H1 a la izquierda
 * y bloque lime con stats grandes a la derecha. En mobile, el lime cae debajo.
 *
 * Compone `HeroHeadline` (lado tipográfico) + `HeroPanel` (bloque lime) —
 * los dos componentes shared del DS que usa también el marketing. Coherencia
 * automática: editar tipografía o lime en un sólo sitio.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2b. Usar una sola vez por pagina —
 * es el "hero", no se repite.
 */
export function KpiHero({
    eyebrow,
    title,
    description,
    actions,
    stats,
    statsEyebrow,
    className,
}: KpiHeroProps) {
    const trimmedStats = stats.slice(0, 3);

    return (
        <section className={cn('grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-10 lg:gap-12', className)}>
            <HeroHeadline
                eyebrow={eyebrow}
                title={title}
                description={description}
                size="lg"
                actions={actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                className="md:col-span-7 lg:col-span-8"
            />

            <HeroPanel
                eyebrow={statsEyebrow}
                className="justify-center md:col-span-5 lg:col-span-4"
            >
                <HeroPanelStats stats={trimmedStats} layout="grid" />
            </HeroPanel>
        </section>
    );
}
