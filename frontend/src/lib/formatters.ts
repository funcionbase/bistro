import type { Invoice } from '@/types';

// TZ canónica para todos los formateos del frontend. Centralizada para evitar
// drift cuando un admin se conecta desde otra zona horaria.
const TZ = 'America/Bogota';

export function formatCOP(amount: number): string {
    return new Intl.NumberFormat('es-CO', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
}

export function formatMonthYear(isoDate: string): string {
    const d = new Date(isoDate + 'T00:00:00');
    const str = d.toLocaleString('es-CO', { month: 'short', year: 'numeric', timeZone: TZ });
    return str.charAt(0).toUpperCase() + str.slice(1);
}

export function formatDate(isoDate: string): string {
    return new Date(isoDate + 'T00:00:00').toLocaleDateString('es-CO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: TZ,
    });
}

export function formatInvoicePeriod(invoice: Invoice): string {
    if (invoice.type === 'proration') {
        const from = new Date(invoice.period_from + 'T00:00:00');
        const to = new Date(invoice.period_to + 'T00:00:00');
        const month = from.toLocaleString('es-CO', { month: 'short', timeZone: TZ });
        const year = from.getFullYear();
        return `${from.getDate()}–${to.getDate()} ${month.charAt(0).toUpperCase() + month.slice(1)} ${year}`;
    }
    return formatMonthYear(invoice.period_from);
}

export function nextBillingDate(): string {
    const now = new Date();
    let year = now.getFullYear();
    let month = now.getMonth();

    if (now.getDate() >= 20) {
        month += 1;
        if (month > 11) {
            month = 0;
            year += 1;
        }
    }

    const next = new Date(year, month, 20);
    return next.toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric', timeZone: TZ });
}
