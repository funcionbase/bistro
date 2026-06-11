import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { apiFetch } from '@/lib/api';
import { formatCOP } from '@/lib/formatters';
import { route } from '@/lib/route-compat';
import { useSharedData } from '@/lib/shared-data';
import { AlertOctagon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';

/**
 * Banner persistente de cuenta suspendida (issue #193, status `suspended`).
 *
 * Se monta globalmente desde el layout autenticado de la SPA. Muestra:
 *  - Días desde el bloqueo (derivado de `payment_blocked_at`).
 *  - Monto adeudado (fetch a `/api/v1/billing/subscription` que ya devuelve
 *    `overdue_total + earliest_overdue_date`). Skeleton inline mientras carga
 *    — el CTA y el resto del mensaje son visibles desde el primer render.
 *  - CTA prominente a `/billing` para subir comprobante de pago.
 *
 * Por qué fetch on-demand: el endpoint ya existe y se llama desde `/billing`.
 * Incluir `overdue_total` en el contexto global de la SPA agregaría una query
 * SQL al bootstrap incluso cuando la página no renderiza el banner. El fetch
 * on-demand mantiene el costo en cero salvo para empresas suspendidas.
 *
 * Renderiza `null` si la empresa no está en `suspended` o no hay activeCompany.
 */
export default function SuspendedBanner() {
    const navigate = useNavigate();
    const { activeCompany } = useSharedData();
    const [overdueTotal, setOverdueTotal] = useState<number | null>(null);
    const [loadingTotal, setLoadingTotal] = useState(true);

    const isSuspended = activeCompany?.status === 'suspended';

    useEffect(() => {
        if (!isSuspended) {
            setOverdueTotal(null);
            setLoadingTotal(false);
            return;
        }

        let cancelled = false;
        setLoadingTotal(true);

        (async () => {
            try {
                const res = await apiFetch('/api/v1/billing/subscription');
                if (!cancelled && res.ok) {
                    const data = await res.json();
                    setOverdueTotal(Number(data.overdue_total ?? 0));
                }
            } catch {
                // Silencioso: el banner sigue funcional sin el monto.
            } finally {
                if (!cancelled) setLoadingTotal(false);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [isSuspended, activeCompany?.nit]);

    const daysOverdue = useMemo(() => {
        if (!activeCompany?.payment_blocked_at) return null;
        const blocked = new Date(activeCompany.payment_blocked_at);
        const diff = Math.floor((Date.now() - blocked.getTime()) / (1000 * 60 * 60 * 24));
        return Math.max(0, diff);
    }, [activeCompany?.payment_blocked_at]);

    if (!activeCompany || !isSuspended) {
        return null;
    }

    const blockedText =
        daysOverdue === null
            ? 'Tu cuenta está bloqueada por facturas vencidas.'
            : daysOverdue === 0
              ? 'Tu cuenta fue bloqueada hoy por mora prolongada.'
              : `Tu cuenta está bloqueada hace ${daysOverdue} ${daysOverdue === 1 ? 'día' : 'días'} por mora prolongada.`;

    return (
        <div className="px-4 pt-4 sm:px-6 md:px-8">
            <Alert variant="critical">
                <AlertOctagon className="h-5 w-5" />
                <AlertTitle>Cuenta suspendida</AlertTitle>
                <AlertDescription className="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <p>
                            {blockedText}{' '}
                            {loadingTotal ? (
                                <Skeleton className="inline-block h-4 w-32 align-middle" />
                            ) : overdueTotal !== null && overdueTotal > 0 ? (
                                <>
                                    Adeudas <span className="font-bold tabular-nums">$ {formatCOP(overdueTotal)} COP</span>.
                                </>
                            ) : null}
                        </p>
                        <p className="text-xs opacity-80">
                            Sube el comprobante de pago para reactivar el acceso. El cobro se valida y tu cuenta vuelve a operar dentro de las
                            próximas horas.
                        </p>
                    </div>
                    <Button size="sm" className="shrink-0" onClick={() => navigate(route('billing'))}>
                        Ir a Facturación
                    </Button>
                </AlertDescription>
            </Alert>
        </div>
    );
}
