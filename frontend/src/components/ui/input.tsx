import * as React from 'react';

import { cn } from '@/lib/utils';

// Teclas que introducen decimales o notación exponencial. El signo `-` se
// permite a propósito: un entero negativo sigue siendo entero (ej. ajuste de
// puntos de lealtad "-50").
const DECIMAL_KEYS = new Set(['.', ',', 'e', 'E']);
const DECIMAL_PASTE = /[.,eE]/;

/**
 * Input del DS.
 *
 * **Política global de inputs numéricos:** cuando `type="number"`, la captura es
 * SOLO de enteros. La base de datos conserva decimales para cálculos, pero
 * ningún input del panel permite tipear/pegar decimales. Esto se centraliza acá
 * para garantizar cobertura uniforme (todos los `type="number"` pasan por este
 * primitive):
 * - `step` fraccionario (0.01, 0.001, …) se neutraliza a `1` para que el spinner
 *   no genere decimales; un `step` entero explícito se respeta.
 * - `inputMode` se fuerza a `numeric` (teclado sin punto decimal en móvil).
 * - Se bloquean las teclas `.`, `,`, `e`, `E` y el pegado de valores con
 *   separador decimal / exponente. Los handlers del consumidor se encadenan.
 */
const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(
    ({ className, type, step, inputMode, onKeyDown, onPaste, ...props }, ref) => {
        const isNumber = type === 'number';

        const resolvedStep = React.useMemo(() => {
            if (!isNumber) {
                return step;
            }
            const numeric = typeof step === 'number' ? step : step != null ? Number(step) : NaN;
            return Number.isInteger(numeric) && numeric > 0 ? step : '1';
        }, [isNumber, step]);

        const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
            if (isNumber && DECIMAL_KEYS.has(event.key)) {
                event.preventDefault();
            }
            onKeyDown?.(event);
        };

        const handlePaste = (event: React.ClipboardEvent<HTMLInputElement>) => {
            if (isNumber && DECIMAL_PASTE.test(event.clipboardData.getData('text'))) {
                event.preventDefault();
            }
            onPaste?.(event);
        };

        return (
            <input
                type={type}
                step={resolvedStep}
                inputMode={isNumber ? 'numeric' : inputMode}
                onKeyDown={handleKeyDown}
                onPaste={handlePaste}
                className={cn(
                    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                    className,
                )}
                ref={ref}
                {...props}
            />
        );
    },
);

Input.displayName = 'Input';

export { Input };
