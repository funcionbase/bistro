import { type ReactNode } from 'react';
import { LoaderCircle } from 'lucide-react';

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { TOOLTIP_DELAY_MS } from '@/lib/shortcuts';
import { cn } from '@/lib/utils';

interface SelectableTileProps {
    /** Contenido libre dentro del tile (icono/logo + nombre + badge). */
    children: ReactNode;
    /** Callback al click. Ignorado si `disabled` está activo. */
    onClick?: () => void;
    /** Tile no clickable. Si se pasa `disabledTooltip` se envuelve en Tooltip. */
    disabled?: boolean;
    /** Texto mostrado en Tooltip cuando `disabled` es true. */
    disabledTooltip?: string;
    /** Muestra spinner inferior y bloquea interacción. */
    loading?: boolean;
    className?: string;
}

/**
 * Wrapper estándar para tiles seleccionables en pantallas de elección
 * (selector de empresa, selector de sede, paso de onboarding tipo "elige
 * tu plantilla"). Aplica el patrón visual v3.1: rounded-xl, border, shadow-sm,
 * focus-visible ring del DS, hover con border-primary/50, disabled con
 * opacity-50 + cursor-not-allowed.
 *
 * El contenido interno queda libre — la página decide qué meter (avatar,
 * icono, nombre, badge de estado, overlays). Sin estados de selección
 * persistente (no usa aria-pressed); para selección persistente usar
 * `ToggleGroup` del shadcn.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo) y §17 (accesibilidad).
 */
export function SelectableTile({
    children,
    onClick,
    disabled = false,
    disabledTooltip,
    loading = false,
    className,
}: SelectableTileProps) {
    const tile = (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled || loading}
            className={cn(
                'bg-card focus-visible:ring-ring relative flex w-full flex-col items-center gap-4 rounded-xl border p-6 text-left shadow-sm transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                !disabled
                    ? 'enabled:hover:border-primary/50 enabled:hover:shadow-md cursor-pointer'
                    : 'cursor-not-allowed opacity-50',
                className,
            )}
        >
            {children}
            {loading && (
                <span className="mt-2 flex items-center justify-center" aria-hidden>
                    <LoaderCircle className="text-primary h-5 w-5 animate-spin" />
                </span>
            )}
        </button>
    );

    if (disabled && disabledTooltip) {
        return (
            <TooltipProvider delayDuration={TOOLTIP_DELAY_MS}>
                <Tooltip>
                    <TooltipTrigger asChild>{tile}</TooltipTrigger>
                    <TooltipContent>
                        <p>{disabledTooltip}</p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        );
    }

    return tile;
}
