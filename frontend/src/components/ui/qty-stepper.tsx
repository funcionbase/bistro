import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Minus, Plus } from 'lucide-react';

interface QtyStepperProps {
    value: number;
    onChange: (next: number) => void;
    min?: number;
    max?: number;
    disabled?: boolean;
    /**
     * Etiqueta accesible del control completo. El stepper es un grupo de
     * 2 botones + display; el `aria-label` ayuda a screen readers.
     */
    label?: string;
    /**
     * Compacto reduce el tamaño de los botones a `size="sm"` (32px). Solo
     * usar en contextos desktop donde no hay touch (KDS, caja en monitor).
     * Default: false — usa `size="icon"` (44×44) para cumplir touch target.
     */
    compact?: boolean;
    className?: string;
}

/**
 * Stepper numérico − valor + con touch targets de 44×44 (mobile-first).
 *
 * Reemplaza el patrón duplicado de dos botones con dash/plus en hardcode
 * (carrito QR mesa, NotesEditor de cantidades, edición de receta). Usa el
 * Button del DS, así hereda `min-h-[44px] min-w-[44px]` automáticamente.
 *
 * El display central usa `tabular-nums` y ancho fijo `w-10` para que el
 * número no rebote al pasar de 1 dígito a 2 dígitos.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §10 (Checklist mobile obligatorio).
 */
export function QtyStepper({
    value,
    onChange,
    min = 1,
    max = 99,
    disabled = false,
    label = 'Cantidad',
    compact = false,
    className,
}: QtyStepperProps) {
    const canDec = !disabled && value > min;
    const canInc = !disabled && value < max;
    const buttonSize = compact ? 'sm' : 'icon';

    return (
        <div
            role="group"
            aria-label={label}
            className={cn('inline-flex items-center gap-2', className)}
        >
            <Button
                type="button"
                size={buttonSize}
                variant="secondary"
                onClick={() => onChange(Math.max(min, value - 1))}
                disabled={!canDec}
                aria-label={`Disminuir ${label.toLowerCase()}`}
            >
                <Minus className="h-4 w-4" aria-hidden />
            </Button>
            <span
                aria-live="polite"
                className={cn(
                    'inline-flex min-w-10 justify-center text-center font-semibold tabular-nums',
                    compact ? 'text-sm' : 'text-base',
                )}
            >
                {value}
            </span>
            <Button
                type="button"
                size={buttonSize}
                variant="secondary"
                onClick={() => onChange(Math.min(max, value + 1))}
                disabled={!canInc}
                aria-label={`Aumentar ${label.toLowerCase()}`}
            >
                <Plus className="h-4 w-4" aria-hidden />
            </Button>
        </div>
    );
}
