import { z } from 'zod';
import { sanitizePlainText } from '../input-sanitize';

/**
 * Schemas reutilizables para texto sanitizado en formularios cliente.
 *
 * El `transform` aplica `sanitizePlainText` automáticamente; el output
 * del parse ya viene limpio. El cliente nunca confía en este resultado
 * — el backend revalida con `SafePlainText` y el trait `SanitizesInput`.
 *
 * Ver `docs/wiki/SECURITY_INPUT_HANDLING.md`.
 */

export function plainTextShort(maxLength: number, { optional = false }: { optional?: boolean } = {}) {
    const base = z.string().transform((v) => sanitizePlainText(v, maxLength, false));
    if (optional) {
        return z
            .string()
            .nullish()
            .transform((v) => (v == null || v === '' ? '' : sanitizePlainText(v, maxLength, false)));
    }
    return base.pipe(z.string().min(1, { message: 'Este campo es obligatorio.' }));
}

export function plainTextLong(maxLength: number, { optional = true }: { optional?: boolean } = {}) {
    if (optional) {
        return z
            .string()
            .nullish()
            .transform((v) => (v == null || v === '' ? '' : sanitizePlainText(v, maxLength, true)));
    }
    return z
        .string()
        .transform((v) => sanitizePlainText(v, maxLength, true))
        .pipe(z.string().min(1, { message: 'Este campo es obligatorio.' }));
}
