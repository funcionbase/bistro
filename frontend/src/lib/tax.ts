/**
 * Helpers de impuestos espejo del TaxCalculator de PHP. Sirven SOLO como preview
 * UX en caja/mesas — el cálculo autoritativo es el del backend, que recalcula al
 * persistir la orden y devuelve el desglose final.
 */

export interface TaxBreakdown {
    subtotal: number;
    tax_amount: number;
    total: number;
}

const round2 = (n: number): number => Math.round(n * 100) / 100;

export function calculateTaxLine(price: number, quantity: number, taxRate: number, taxIncluded: boolean): TaxBreakdown {
    const rate = Math.max(0, taxRate);
    const lineGross = round2(price * quantity);

    if (taxIncluded) {
        const total = lineGross;
        const subtotal = rate > 0 ? round2(total / (1 + rate / 100)) : total;
        const tax = round2(total - subtotal);
        return { subtotal, tax_amount: tax, total };
    }

    const subtotal = lineGross;
    const tax = round2(subtotal * (rate / 100));
    return { subtotal, tax_amount: tax, total: round2(subtotal + tax) };
}

export function aggregateTax(lines: TaxBreakdown[]): TaxBreakdown {
    return lines.reduce<TaxBreakdown>(
        (acc, line) => ({
            subtotal: round2(acc.subtotal + line.subtotal),
            tax_amount: round2(acc.tax_amount + line.tax_amount),
            total: round2(acc.total + line.total),
        }),
        { subtotal: 0, tax_amount: 0, total: 0 },
    );
}
