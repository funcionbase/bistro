import { type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface PeriodNavigatorProps {
    /** Etiqueta del periodo activo (ej. "Semana del 5 al 11 de mayo"). */
    label: ReactNode;
    /** Handler para retroceder al periodo anterior. */
    onPrev: () => void;
    /** Handler para avanzar al periodo siguiente. */
    onNext: () => void;
    /** Handler para volver al periodo actual (Hoy). Si se omite, oculta el botón. */
    onToday?: () => void;
    /** Texto del botón central. Default: "Hoy". */
    todayLabel?: string;
    /** Deshabilita los controles (loading). */
    disabled?: boolean;
    className?: string;
}

/**
 * Navegador de período con prev/today/next + label visible del rango activo.
 * Patrón reutilizado en /planner, /planner/calendar, /me/agenda,
 * /clients (filtro de cohortes), etc.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §7 y §10.
 */
export function PeriodNavigator({
    label,
    onPrev,
    onNext,
    onToday,
    todayLabel = 'Hoy',
    disabled = false,
    className,
}: PeriodNavigatorProps) {
    return (
        <div className={cn('flex flex-wrap items-center gap-2', className)}>
            <Button
                variant="outline"
                size="sm"
                onClick={onPrev}
                disabled={disabled}
                aria-label="Periodo anterior"
            >
                <ChevronLeft className="h-4 w-4" />
            </Button>
            {onToday && (
                <Button variant="outline" size="sm" onClick={onToday} disabled={disabled}>
                    {todayLabel}
                </Button>
            )}
            <Button
                variant="outline"
                size="sm"
                onClick={onNext}
                disabled={disabled}
                aria-label="Periodo siguiente"
            >
                <ChevronRight className="h-4 w-4" />
            </Button>
            {label && (
                <span className="text-muted-foreground ml-1 truncate text-sm">{label}</span>
            )}
        </div>
    );
}
