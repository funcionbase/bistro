import type { Invoice } from '@/types';

// TZ canónica para todos los formateos del frontend. Centralizada para evitar
// drift cuando un admin se conecta desde otra zona horaria.
const TZ = 'America/Bogota';

/**
 * Parsea un string date-only (`YYYY-MM-DD`) como medianoche UTC. Los valores
 * date-only de facturación (due_date, period_from, etc.) NO tienen hora ni TZ;
 * anclarlos a UTC y formatearlos con `timeZone: 'UTC'` garantiza que el día
 * mostrado sea exactamente el del string, sin desfases por la TZ del navegador.
 * (Con `'T00:00:00'` local + `timeZone: 'America/Bogota'` un navegador al este
 * de Bogotá veía la fecha corrida un día.)
 */
function parseIsoDateUtc(isoDate: string): Date {
    return new Date(isoDate.slice(0, 10) + 'T00:00:00Z');
}

// Instancias Intl cacheadas a nivel de módulo: construir un NumberFormat por
// llamada es caro y estos formatters corren por celda en tablas grandes.
const COP_NUMBER_FORMAT = new Intl.NumberFormat('es-CO', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});
const COP_CURRENCY_FORMAT = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

export function formatCOP(amount: number): string {
    // §13: facturación CO se muestra sin decimales TRUNCANDO a peso (decisión de
    // presentación). `Intl` redondea, así que truncamos antes de formatear.
    return COP_NUMBER_FORMAT.format(Math.trunc(amount));
}

/**
 * Formato canónico de moneda COP con símbolo ($ 12.500), sin decimales.
 * §13: presentación trunca a peso — antes ~28 copias locales redondeaban vía
 * Intl y el mismo monto podía pintar distinto entre pantallas.
 */
export function formatCurrency(value: number): string {
    if (!Number.isFinite(value)) {
        value = 0;
    }
    return COP_CURRENCY_FORMAT.format(Math.trunc(value));
}

export function formatMonthYear(isoDate: string): string {
    const str = parseIsoDateUtc(isoDate).toLocaleString('es-CO', { month: 'short', year: 'numeric', timeZone: 'UTC' });
    return str.charAt(0).toUpperCase() + str.slice(1);
}

export function formatDate(isoDate: string): string {
    return parseIsoDateUtc(isoDate).toLocaleDateString('es-CO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

export function formatInvoicePeriod(invoice: Invoice): string {
    if (invoice.type === 'proration') {
        const from = parseIsoDateUtc(invoice.period_from);
        const to = parseIsoDateUtc(invoice.period_to);
        const month = from.toLocaleString('es-CO', { month: 'short', timeZone: 'UTC' });
        const year = from.getUTCFullYear();
        return `${from.getUTCDate()}–${to.getUTCDate()} ${month.charAt(0).toUpperCase() + month.slice(1)} ${year}`;
    }
    return formatMonthYear(invoice.period_from);
}

export function nextBillingDate(): string {
    // "Hoy" en Bogotá: la facturación corre el día 20 hora Colombia, así que el
    // umbral debe evaluarse en esa TZ, no en la del navegador.
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
    const get = (type: string): number => Number(parts.find((p) => p.type === type)?.value);
    let year = get('year');
    let month = get('month') - 1; // Intl da mes 1-based; Date espera 0-based.
    const day = get('day');

    if (day >= 20) {
        month += 1;
        if (month > 11) {
            month = 0;
            year += 1;
        }
    }

    // Anclar a UTC + formatear en UTC para que el día 20 no se corra por TZ.
    const next = new Date(Date.UTC(year, month, 20));
    return next.toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
}
