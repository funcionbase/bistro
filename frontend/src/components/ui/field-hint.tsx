import { Info } from 'lucide-react';
import * as React from 'react';

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

interface FieldHintProps {
    /** Texto de ayuda. Alternativamente usar `children` para contenido rico. */
    text?: string;
    children?: React.ReactNode;
    /** Lado del tooltip respecto del ícono. Default: `top`. */
    side?: 'top' | 'right' | 'bottom' | 'left';
    /** Clases extra para el ícono disparador. */
    className?: string;
}

/**
 * Ícono de ayuda (i) con tooltip — aclara campos ambiguos sin saturar la UI.
 *
 * Primitive del DS: unifica el patrón que antes se duplicaba ad-hoc en
 * `employee-form` y `company/branches`. Es autocontenido (trae su
 * `TooltipProvider`), accesible (trigger `<button>` con `aria-label`, focusable
 * por teclado) y usa tokens semánticos. Colocar junto al `<Label>` del campo.
 */
export function FieldHint({ text, children, side = 'top', className }: FieldHintProps) {
    return (
        <TooltipProvider delayDuration={150}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        aria-label="Más información"
                        className={cn(
                            'text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex rounded-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                            className,
                        )}
                    >
                        <Info className="h-3.5 w-3.5" aria-hidden="true" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side={side} className="max-w-xs space-y-1 text-xs leading-snug">
                    {children ?? text}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

interface ReasonTooltipProps {
    /** Motivo a mostrar. Si es falsy, el tooltip no existe y se renderiza el hijo tal cual. */
    reason?: string | null;
    children: React.ReactNode;
    side?: 'top' | 'right' | 'bottom' | 'left';
}

/**
 * Tooltip para explicar POR QUÉ un control está deshabilitado (§8.4c).
 *
 * El caso crítico: un botón `disabled` NO dispara eventos de mouse, así que
 * Radix nunca mostraría el tooltip. Por eso el hijo va envuelto en un
 * `<span tabIndex={0}>` que ES el trigger — sin ese wrapper el tooltip más
 * importante del panel (el motivo de una acción gris) se implementa y no
 * aparece nunca. Es la regla 2 de §8.4c.
 *
 * Reutiliza los primitivos del DS; no crea nada nuevo. Si no hay `reason`, no
 * envuelve: un control habilitado no necesita explicación.
 */
export function ReasonTooltip({ reason, children, side = 'top' }: ReasonTooltipProps) {
    if (!reason) {
        return <>{children}</>;
    }

    return (
        <TooltipProvider delayDuration={150}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span tabIndex={0} className="inline-flex">
                        {children}
                    </span>
                </TooltipTrigger>
                <TooltipContent side={side} className="max-w-xs text-xs leading-snug">
                    {reason}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
