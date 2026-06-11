import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { isFullyBlocked } from '@/lib/company-status';
import { formatCOP, formatDate } from '@/lib/formatters';
import { AlertTriangle } from 'lucide-react';

interface Props {
    companyStatus: string;
    overdueTotal: number;
    earliestOverdueDate: string | null;
}

/**
 * Banner contable de mora. Se muestra solo si la empresa está en `past_due`
 * o `suspended` — silencioso en cualquier otro estado.
 *
 * Mapea al token del semáforo:
 *  - suspended → critical (acceso restringido, deuda prolongada)
 *  - past_due  → warning (factura vencida, aún operativa)
 */
export default function OverdueBanner({ companyStatus, overdueTotal, earliestOverdueDate }: Props) {
    if (companyStatus !== 'past_due' && !isFullyBlocked(companyStatus)) {
        return null;
    }

    const isSuspended = isFullyBlocked(companyStatus);

    return (
        <Alert variant={isSuspended ? 'critical' : 'warning'}>
            <AlertTriangle className="h-5 w-5" />
            <AlertTitle>{isSuspended ? 'Cuenta suspendida por mora prolongada' : 'Tienes facturas pendientes de pago'}</AlertTitle>
            <AlertDescription className="space-y-1">
                <p>
                    Total vencido: <span className="font-bold tabular-nums">$ {formatCOP(overdueTotal)} COP</span>
                    {earliestOverdueDate && <> — vencimiento más próximo: {formatDate(earliestOverdueDate)}</>}
                </p>
                {isSuspended && (
                    <p className="text-xs opacity-80">
                        Sube el comprobante de pago para reactivar tu cuenta. El acceso operativo está restringido hasta liquidar la deuda.
                    </p>
                )}
            </AlertDescription>
        </Alert>
    );
}
