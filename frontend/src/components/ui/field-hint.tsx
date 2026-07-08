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
