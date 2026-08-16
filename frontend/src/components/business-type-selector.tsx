import { type BusinessTypeOption, useBusinessTypes } from '@/hooks/use-business-types';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
    Beer,
    Cake,
    Coffee,
    Croissant,
    Flame,
    Info,
    Martini,
    Store,
    Truck,
    Utensils,
    UtensilsCrossed,
    type LucideIcon,
} from 'lucide-react';

const ICON_MAP: Record<string, LucideIcon> = {
    utensils: Utensils,
    croissant: Croissant,
    coffee: Coffee,
    burger: UtensilsCrossed,
    truck: Truck,
    'chef-hat': Flame,
    martini: Martini,
    'utensils-crossed': UtensilsCrossed,
    store: Store,
    flame: Flame,
    cake: Cake,
    beer: Beer,
};

/**
 * Etiquetas legibles para las capabilities canónicas. Sólo se muestran las
 * habilitadas en `default_capabilities`. La tabla la mantiene este componente
 * y NO el catálogo (orientado a humanos, no a slugs).
 */
const CAPABILITY_LABELS: Record<string, string> = {
    pos_orders: 'Toma de pedidos',
    counter_orders: 'Mostrador',
    tables: 'Mesas',
    kds: 'Pantalla de cocina (KDS)',
    prep_areas: 'Áreas de preparación',
    delivery: 'Domicilios',
    recipes: 'Recetas',
    inventory: 'Inventario',
    reservations: 'Reservas',
    catering_scheduling: 'Programación de eventos',
    multi_menu: 'Múltiples menús',
};

export interface BusinessTypeSelectorProps {
    value: string | null;
    onChange: (slug: string) => void;
    disabledSlugs?: string[];
    layout?: 'grid' | 'list';
    /** Permite filtrar el catálogo si se quiere ocultar verticales (ej. demos). */
    filter?: (option: BusinessTypeOption) => boolean;
}

/**
 * Componente reutilizable para seleccionar un vertical de negocio.
 *
 * Reusado en:
 *   - Wizard de onboarding (paso "primera sede").
 *   - Selector "cambiar tipo de negocio" de una sede existente.
 *   - Creación de nueva sede dentro de empresa con sedes mixtas.
 *
 * Muestra cada opción como una card con icono + label_es + tooltip explicativo
 * (capabilities activas + áreas de preparación por defecto). La card
 * seleccionada se distingue con borde primary + check visual.
 */
export function BusinessTypeSelector({ value, onChange, disabledSlugs, layout = 'grid', filter }: BusinessTypeSelectorProps) {
    const query = useBusinessTypes();
    const disabled = new Set(disabledSlugs ?? []);

    if (query.isLoading) {
        return (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {Array.from({ length: 6 }).map((_, i) => (
                    <Skeleton key={i} className="h-24 w-full rounded-lg" />
                ))}
            </div>
        );
    }

    if (query.isError) {
        return (
            <p className="text-destructive text-sm">No se pudo cargar el catálogo de tipos de negocio. Intenta recargar la página.</p>
        );
    }

    const options = (query.data ?? []).filter((opt) => (filter ? filter(opt) : true));

    return (
        <TooltipProvider delayDuration={200}>
            <div
                role="radiogroup"
                className={cn(
                    'grid gap-3',
                    layout === 'grid' ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3' : 'grid-cols-1',
                )}
            >
                {options.map((opt) => {
                    const Icon: LucideIcon = (opt.icon_key ? ICON_MAP[opt.icon_key] : undefined) ?? Utensils;
                    const selected = value === opt.slug;
                    const isDisabled = disabled.has(opt.slug);
                    const activeCaps = Object.entries(opt.default_capabilities)
                        .filter(([, on]) => on)
                        .map(([cap]) => CAPABILITY_LABELS[cap] ?? cap);
                    const areas = (opt.prep_area_defaults ?? []).map((a) => a.label);

                    return (
                        <button
                            key={opt.slug}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            aria-label={opt.label_es}
                            disabled={isDisabled}
                            onClick={() => onChange(opt.slug)}
                            // `min-w-0` permite que la card encoja debajo de su contenido intrínseco
                            // (sin esto, dentro de un grid angosto los textos largos empujan
                            // el ancho y desbordan por la derecha de la card).
                            className={cn(
                                'group bg-card text-card-foreground relative flex min-w-0 items-start gap-3 rounded-lg border p-4 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40',
                                selected ? 'border-primary ring-2 ring-primary/20' : 'border-border hover:border-primary/50',
                                isDisabled && 'cursor-not-allowed opacity-50',
                            )}
                        >
                            <div
                                className={cn(
                                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-md',
                                    selected ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                                )}
                            >
                                <Icon className="h-5 w-5" />
                            </div>
                            <div className="min-w-0 flex-1 space-y-1">
                                <div className="flex items-center gap-2">
                                    <span className="truncate text-sm font-semibold leading-snug" title={opt.label_es}>
                                        {opt.label_es}
                                    </span>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <span
                                                tabIndex={0}
                                                aria-label={`Detalles de ${opt.label_es}`}
                                                className="text-muted-foreground hover:text-foreground shrink-0 focus:outline-none"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                <Info className="h-3.5 w-3.5" />
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent side="top" align="start" className="max-w-xs space-y-2">
                                            {activeCaps.length > 0 && (
                                                <div>
                                                    <p className="text-xs font-medium uppercase opacity-70">Incluye</p>
                                                    <p className="text-xs leading-snug">{activeCaps.join(', ')}</p>
                                                </div>
                                            )}
                                            {areas.length > 0 && (
                                                <div>
                                                    <p className="text-xs font-medium uppercase opacity-70">Áreas de preparación</p>
                                                    <p className="text-xs leading-snug">{areas.join(', ')}</p>
                                                </div>
                                            )}
                                            {areas.length === 0 && (
                                                <p className="text-xs leading-snug opacity-70">Sin áreas de preparación.</p>
                                            )}
                                        </TooltipContent>
                                    </Tooltip>
                                </div>
                                {/* line-clamp-2 + break-words: el resumen de capabilities puede
                                    ser largo. Permitimos dos líneas y, ante palabras muy largas
                                    sin espacios, quebrar para no salir del ancho de la card. */}
                                <p className="text-muted-foreground line-clamp-2 break-words text-xs leading-snug">
                                    {activeCaps.slice(0, 3).join(' · ')}
                                </p>
                            </div>
                        </button>
                    );
                })}
            </div>
        </TooltipProvider>
    );
}
