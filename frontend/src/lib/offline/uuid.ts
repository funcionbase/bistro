/**
 * UUID v4 generado en el cliente para idempotencia de órdenes/cobros offline.
 *
 * Usa `crypto.randomUUID()` cuando está disponible (Chrome 92+, Safari 15.4+,
 * Firefox 95+) y cae a un fallback puro JS si no.
 */
export function uuidv4(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    // Fallback RFC4122 v4. No tan robusto como crypto.randomUUID pero suficiente
    // para diferenciar batches; el server hace el lock idempotente igual.
    const bytes = new Uint8Array(16);
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));
    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10, 16).join('')}`;
}
