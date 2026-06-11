import { usePaymentMethods } from '@/hooks/use-payment-methods';
import { cn } from '@/lib/utils';
import type { PaymentMethod } from '@/types';

interface RefundSummaryCardProps {
    total: number;
    /** Monto ya devuelto previamente (si > 0 se muestra en warning). */
    alreadyRefunded: number;
    /** Remanente reembolsable (total - alreadyRefunded). */
    remaining: number;
    /** Metodo original del cobro. Null si no hay receipt registrado. */
    method: PaymentMethod | null;
    formatCurrency: (value: number) => string;
    className?: string;
}

/**
 * Resumen contable previo a una devolucion. Muestra total, ya devuelto
 * (cuando aplica), remanente y metodo original. Usado dentro del
 * `RefundModal` de la pagina de ventas del dia y reutilizable para
 * cualquier flujo de refund (orden, factura, etc.).
 *
 * Convencion de colores:
 *  - Ya devuelto previamente: `status-warning` (advierte que hay historico).
 *  - Remanente: en peso (font-semibold) para que sea claro cuanto se puede
 *    devolver.
 */
export function RefundSummaryCard({ total, alreadyRefunded, remaining, method, formatCurrency, className }: RefundSummaryCardProps) {
    const catalog = usePaymentMethods();
    return (
        <div className={cn('bg-muted/30 space-y-1 rounded-md border p-3 text-sm', className)}>
            <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Total de la orden</span>
                <span className="tabular-nums">{formatCurrency(total)}</span>
            </div>
            {alreadyRefunded > 0 && (
                <div className="flex items-center justify-between text-[color:var(--color-status-warning)]">
                    <span>Ya devuelto previamente</span>
                    <span className="tabular-nums">{formatCurrency(alreadyRefunded)}</span>
                </div>
            )}
            <div className="flex items-center justify-between border-t pt-1">
                <span className="font-semibold">Remanente reembolsable</span>
                <span className="font-semibold tabular-nums">{formatCurrency(remaining)}</span>
            </div>
            <div className="text-muted-foreground flex items-center justify-between">
                <span>Método original</span>
                <span>{method ? catalog.labels[method] : '—'}</span>
            </div>
        </div>
    );
}
