import { formatDateMedium, timeAgo } from '@/lib/datetime';

/**
 * @deprecated Usar `timeAgo` / `formatDateMedium` de `@/lib/datetime`
 * directamente. Se conserva como alias para no tocar todos los consumidores
 * de una (mismo patrón que `use-currency-formatter`); delega a los helpers
 * canónicos: relativo dentro de la última semana, fecha absoluta en TZ
 * Bogotá después.
 */
export function useDateFormatter() {
    return (isoDate: string): string => {
        const diffDays = Math.floor((Date.now() - new Date(isoDate).getTime()) / 86_400_000);
        return diffDays < 7 ? timeAgo(isoDate) : formatDateMedium(isoDate);
    };
}
