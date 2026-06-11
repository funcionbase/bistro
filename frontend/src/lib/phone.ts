/**
 * Normaliza un teléfono ingresado por el usuario al formato que el backend
 * espera recibir (solo dígitos, **sin** prefijo de país).
 *
 * Reglas:
 *  - Elimina todo lo que no sea dígito (espacios, guiones, paréntesis, "+").
 *  - Si el resultado son 12 dígitos y empieza con "57" (prefijo país
 *    Colombia), se quita ese prefijo y se devuelven los 10 dígitos
 *    restantes.
 *  - Cualquier otro caso se devuelve tal cual (la validación posterior
 *    decide si es aceptable).
 *
 * Convención del backend (CrmService::normalizePhone): si recibe 10 dígitos
 * que empiezan con "3", agrega "57" para almacenar siempre como
 * `573001234567`. Por eso el frontend SOLO debe enviar 10 dígitos — el
 * backend se encarga de prefijar.
 *
 * Ejemplos:
 *   "+57 300 123 4567"   → "3001234567"
 *   "57 3001234567"      → "3001234567"
 *   "3001234567"         → "3001234567"
 *   "300-123-45-67"      → "3001234567"
 *   "(57) 301 555 0000"  → "3015550000"
 *   ""                   → ""
 */
export function stripCountryPrefix(input: string): string {
    const digits = input.replace(/\D+/g, '');
    if (digits === '') {
        return '';
    }
    if (digits.length === 12 && digits.startsWith('57')) {
        return digits.slice(2);
    }
    return digits;
}

/**
 * Validador defensivo del frontend: ¿este string ya luce como un móvil
 * colombiano válido (10 dígitos, prefijo 3)? Se usa después de
 * `stripCountryPrefix`. El backend hace su propia validación regex,
 * pero validar aquí evita un round-trip y muestra el error inline.
 */
export function isValidColombianMobile(digits: string): boolean {
    return /^3\d{9}$/.test(digits);
}
