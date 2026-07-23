/**
 * Helpers de fecha/hora que SIEMPRE usan zona horaria America/Bogota.
 *
 * Toda la operación es en Colombia. Aunque el navegador del cajero
 * normalmente está en Bogotá, un admin que se conecte desde otra TZ vería
 * timestamps inconsistentes con los reportes (que el backend genera en TZ
 * Bogotá). Estos helpers garantizan que todo display sea uniforme.
 *
 * Reglas:
 *  - El backend persiste timestamps en UTC y entrega ISO-8601 con offset.
 *  - El frontend SIEMPRE renderiza en `America/Bogota` (este lib).
 *  - Las fechas "del día" del usuario se interpretan también en Bogotá.
 */

export const APP_TIMEZONE = 'America/Bogota';
export const APP_LOCALE = 'es-CO';

/** ISO-8601 string del día actual en Bogotá (YYYY-MM-DD). */
export function todayInBogota(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: APP_TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}

/**
 * "hace 4 min" / "hace 3h" / "hace 2d". Elapsed, no fecha absoluta.
 *
 * No depende de la zona horaria: es una resta entre dos instantes, y los dos
 * lados vienen en UTC. Vive acá y no dentro de una página porque lo usan la
 * bandeja de chats y las tarjetas de canal, y dos copias de la misma regla se
 * separan a la primera corrección.
 */
export function timeAgo(value: string | Date | null | undefined): string {
    if (!value) return '';

    const date = typeof value === 'string' ? new Date(value) : value;
    const diffMin = Math.floor((Date.now() - date.getTime()) / 60_000);

    if (diffMin < 1) return 'hace un momento';
    if (diffMin < 60) return `hace ${diffMin} min`;

    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return `hace ${diffH}h`;

    return `hace ${Math.floor(diffH / 24)}d`;
}

/**
 * "12 min" / "2h 5min" — cuánto lleva ESPERANDO el cliente (§8.4b punto 2).
 *
 * Distinto de `timeAgo`: no lleva el prefijo "hace" porque se usa dentro de la
 * frase "esperando hace X", y sin el corte en horas un chat de la mañana diría
 * "esperando hace 380 min".
 */
export function waitingFor(value: string | Date | null | undefined): string {
    if (!value) return '';

    const date = typeof value === 'string' ? new Date(value) : value;
    const minutes = Math.max(0, Math.floor((Date.now() - date.getTime()) / 60_000));

    if (minutes < 60) return `${minutes} min`;

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours < 24) return rest === 0 ? `${hours}h` : `${hours}h ${rest}min`;

    return `${Math.floor(hours / 24)}d`;
}

/** Suma/resta días a un YYYY-MM-DD sin drift de TZ. */
export function shiftDate(iso: string, deltaDays: number): string {
    const [y, m, d] = iso.split('-').map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d));
    dt.setUTCDate(dt.getUTCDate() + deltaDays);
    return dt.toISOString().slice(0, 10);
}

function asDate(value: string | Date | null | undefined): Date | null {
    if (value === null || value === undefined) return null;
    return value instanceof Date ? value : new Date(value);
}

/** "08/05/26 14:32" — corto en TZ Bogotá. */
export function formatDateTimeShort(value: string | Date | null | undefined): string {
    const d = asDate(value);
    if (!d || Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat(APP_LOCALE, {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: APP_TIMEZONE,
    }).format(d);
}

/** "8 may 2026" — fecha sola, formato medium. */
export function formatDateMedium(value: string | Date | null | undefined): string {
    const d = asDate(value);
    if (!d || Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat(APP_LOCALE, {
        dateStyle: 'medium',
        timeZone: APP_TIMEZONE,
    }).format(d);
}

/** "14:32" — hora sola HH:mm en TZ Bogotá. */
export function formatTimeOnly(value: string | Date | null | undefined): string {
    const d = asDate(value);
    if (!d || Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat(APP_LOCALE, {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: APP_TIMEZONE,
    }).format(d);
}

/** "viernes, 8 de mayo de 2026" — formato largo. */
export function formatDateLong(value: string | Date | null | undefined): string {
    const d = asDate(value);
    if (!d || Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat(APP_LOCALE, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: APP_TIMEZONE,
    }).format(d);
}

/**
 * Formato custom forzando TZ Bogotá. Atajo para casos puntuales que
 * necesiten configuración propia (ej. solo mes-año).
 */
export function formatDateTime(value: string | Date | null | undefined, options: Intl.DateTimeFormatOptions): string {
    const d = asDate(value);
    if (!d || Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat(APP_LOCALE, { ...options, timeZone: APP_TIMEZONE }).format(d);
}
