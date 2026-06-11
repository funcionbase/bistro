/**
 * Helpers de saneamiento del lado cliente.
 *
 * El cliente NO garantiza nada — el backend es la única fuente de
 * verdad. Estos helpers existen para:
 * 1. Cortar de inmediato textos absurdamente largos antes de mandar.
 * 2. Bloquear caracteres invisibles en `onChange` para que el usuario
 *    vea feedback al pegar.
 * 3. Compartir las mismas reglas conceptualmente entre cliente y
 *    servidor (auditable).
 *
 * Ver `docs/wiki/SECURITY_INPUT_HANDLING.md` para política completa.
 */

// U+0000–U+001F (control), U+007F (DEL), U+202A–U+202E (bidi overrides).
// Esta regla detecta exactamente lo que queremos eliminar — la regla
// `no-control-regex` está diseñada para prevenir control chars
// accidentales, pero aquí son intencionales.
// eslint-disable-next-line no-control-regex
const CONTROL_CHARS_STRICT = new RegExp('[\\u0000-\\u001F\\u007F\\u202A-\\u202E]', 'g');
// Como STRICT pero preserva \t (U+0009) y \n (U+000A).
// eslint-disable-next-line no-control-regex
const CONTROL_CHARS_ALLOW_WHITESPACE = new RegExp('[\\u0000-\\u0008\\u000B\\u000C\\u000E-\\u001F\\u007F\\u202A-\\u202E]', 'g');

/**
 * Sanea texto plano del cliente:
 * - Remueve tags HTML (`<...>`).
 * - Normaliza Unicode a NFC.
 * - Bloquea control characters y bidi overrides.
 * - Aplica límite por código de unidad (no por bytes — esa es la
 *   responsabilidad del backend con `SafePlainText::sanitize`).
 *
 * @param allowWhitespace si es `true`, preserva `\n` y `\t`; si es
 *   `false`, colapsa cualquier whitespace a un único espacio.
 * @param trim si es `true` (default), recorta espacios al inicio/fin. Pasar
 *   `false` al sanear EN CADA TECLA (onChange) para no comerse el espacio final
 *   mientras se escribe un valor multi-palabra (ej. "Juan Pérez"). El recorte
 *   final ocurre en blur/submit con `trim = true`.
 */
export function sanitizePlainText(value: string, maxLength: number, allowWhitespace = false, trim = true): string {
    if (typeof value !== 'string') {
        return '';
    }

    // strip HTML tags (defense in depth — backend ya lo hace).
    let cleaned = value.replace(/<[^>]*>/g, '');

    // NFC normalization (idempotente con el middleware NormalizeStrings).
    if (typeof cleaned.normalize === 'function') {
        cleaned = cleaned.normalize('NFC');
    }

    // Control chars / bidi overrides — nuevo regex por llamada porque
    // los originales son `/g` y mantienen `lastIndex` (los reusos en
    // assertNoControlChars rompen).
    const pattern = allowWhitespace ? new RegExp(CONTROL_CHARS_ALLOW_WHITESPACE.source, 'g') : new RegExp(CONTROL_CHARS_STRICT.source, 'g');
    cleaned = cleaned.replace(pattern, '');

    if (!allowWhitespace) {
        cleaned = cleaned.replace(/\s+/g, ' ');
    }

    if (trim) {
        cleaned = cleaned.trim();
    }

    if (cleaned.length > maxLength) {
        cleaned = cleaned.slice(0, maxLength);
    }

    return cleaned;
}

/**
 * Devuelve `true` si el valor NO contiene control characters bloqueados.
 * Útil para mostrar mensaje de error al pegar payload con caracteres
 * invisibles sin tener que cortar el input.
 */
export function assertNoControlChars(value: string, allowWhitespace = false): boolean {
    if (typeof value !== 'string') {
        return false;
    }
    const pattern = allowWhitespace ? new RegExp(CONTROL_CHARS_ALLOW_WHITESPACE.source) : new RegExp(CONTROL_CHARS_STRICT.source);
    return !pattern.test(value);
}

/**
 * Strip de HTML simple para casos donde el value ya viene del cliente
 * y no se va a pasar por `sanitizePlainText`. Defensa de cinturón y
 * tirantes — no reemplaza la sanitización del backend.
 */
export function stripDangerousHtml(value: string): string {
    if (typeof value !== 'string') {
        return '';
    }
    return value.replace(/<[^>]*>/g, '');
}

/**
 * Convierte un texto libre (ej. el nombre de una sede/bodega) en un slug
 * SUGERIDO: NFC, sin acentos, minúsculas, cualquier secuencia no alfanumérica
 * pasa a un guion, recorta guiones de los extremos y cap a `maxLength`. El
 * resultado siempre cumple `^[a-z0-9-]*$`. Pensado para presugerir un slug a
 * partir de otro campo — no valida.
 */
export function slugify(value: string, maxLength = 60): string {
    if (typeof value !== 'string') {
        return '';
    }
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '') // quita diacríticos combinantes (á -> a)
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-') // todo lo no alfanumérico -> guion
        .replace(/^-+|-+$/g, '') // recorta guiones de los extremos
        .slice(0, maxLength)
        .replace(/-+$/g, ''); // el slice pudo dejar un guion colgando
}

/**
 * Sanea lo que el usuario TIPEA en un campo de slug, en cada tecla: minúsculas,
 * espacios → guion, descarta cualquier carácter fuera de `[a-z0-9-]` y cap a
 * `maxLength`. NO recorta el guion final para no impedir escribir "centro-"
 * antes de "norte". Espejo de la regex backend `^[a-z0-9-]+$`.
 */
export function sanitizeSlug(value: string, maxLength = 60): string {
    if (typeof value !== 'string') {
        return '';
    }
    return value
        .toLowerCase()
        .replace(/\s+/g, '-') // espacios → guion
        .replace(/[^a-z0-9-]+/g, '') // descarta caracteres inválidos
        .slice(0, maxLength);
}

export type IdentifierKind = 'nit' | 'email' | 'phone' | 'slug' | 'coupon';

const IDENTIFIER_PATTERNS: Record<IdentifierKind, RegExp> = {
    nit: /^[0-9]{5,15}(-[0-9])?$/,
    email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
    phone: /^[+0-9\-\s]{6,30}$/,
    slug: /^[a-z0-9-]{1,60}$/,
    coupon: /^[A-Z0-9\-_]{4,20}$/,
};

/**
 * Valida que `value` cumple la regex del tipo de identifier indicado.
 * Aplica casefold según el kind (email→lower, coupon→upper).
 */
export function assertIdentifier(value: string, kind: IdentifierKind): boolean {
    if (typeof value !== 'string') {
        return false;
    }
    const normalized = kind === 'coupon' ? value.toUpperCase() : kind === 'email' || kind === 'slug' ? value.toLowerCase() : value;
    return IDENTIFIER_PATTERNS[kind].test(normalized.trim());
}
