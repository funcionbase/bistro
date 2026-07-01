import { formatCurrency } from '@/lib/formatters';

/**
 * @deprecated Usar `formatCurrency` de `@/lib/formatters` directamente.
 * Se conserva como alias para no tocar todos los consumidores de una;
 * delega al formateador canónico (trunca a peso, §13).
 */
export function useCurrencyFormatter() {
    return formatCurrency;
}
