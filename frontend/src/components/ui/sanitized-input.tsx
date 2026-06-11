import * as React from 'react';

import { Input } from '@/components/ui/input';
import { sanitizePlainText } from '@/lib/input-sanitize';

interface SanitizedInputProps extends Omit<React.ComponentProps<'input'>, 'onChange' | 'value'> {
    value: string;
    onChange: (value: string) => void;
    /**
     * Longitud máxima por unidades de código (no por bytes — el backend
     * vuelve a recortar por bytes).
     */
    maxLength: number;
    /**
     * `true` para campos largos (notes, description, address) que
     * preservan `\n` y `\t`. `false` (default) para nombres y textos
     * cortos.
     */
    allowWhitespace?: boolean;
}

/**
 * Input controlado que sanea el texto en cada cambio:
 * - Elimina HTML.
 * - Bloquea control characters / bidi overrides.
 * - Aplica límite por código de unidad.
 *
 * Defense in depth — el backend siempre revalida con `SafePlainText`.
 *
 * @example
 * ```tsx
 * const [name, setName] = useState('');
 * <SanitizedInput value={name} onChange={setName} maxLength={120} />
 * ```
 */
export const SanitizedInput = React.forwardRef<HTMLInputElement, SanitizedInputProps>(
    ({ value, onChange, maxLength, allowWhitespace = false, onBlur, ...rest }, ref) => {
        return (
            <Input
                ref={ref}
                value={value}
                onChange={(event) => {
                    // `trim: false` mientras se escribe — si recortáramos en cada
                    // tecla, el espacio final desaparecería y no se podría tipear
                    // un valor multi-palabra (ej. "Juan Pérez").
                    const next = sanitizePlainText(event.target.value, maxLength, allowWhitespace, false);
                    onChange(next);
                }}
                onBlur={(event) => {
                    // Al salir del campo sí recortamos (estado final limpio).
                    onChange(sanitizePlainText(event.target.value, maxLength, allowWhitespace, true));
                    onBlur?.(event);
                }}
                maxLength={maxLength}
                {...rest}
            />
        );
    },
);

SanitizedInput.displayName = 'SanitizedInput';
