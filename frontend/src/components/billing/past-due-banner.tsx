import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { route } from '@/lib/route-compat';
import { useSharedData } from '@/lib/shared-data';
import { AlertTriangle } from 'lucide-react';
import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';

/**
 * Banner blando de mora en período de gracia (issue #193, status `past_due`).
 *
 * Se monta globalmente desde `app-layout.tsx`. Muestra un countdown desde el
 * día 1 (no espera 30 días como la versión anterior) usando `expected_block_at`
 * en TZ America/Bogota. Reusa el componente `Alert` del DS con variant
 * `warning` — colores semánticos del semáforo, no `bg-amber-*` hardcoded.
 *
 * Por qué countdown desde día 1: el issue cambia la política de "silencio
 * el primer mes" hacia "transparencia desde la primera factura vencida".
 * El cliente debe enterarse de inmediato; el bloqueo (suspended) sigue siendo
 * a los 3 meses configurables vía `BILLING_PAST_DUE_GRACE_MONTHS`.
 *
 * Renderiza `null` si la empresa no está en `past_due` o no hay activeCompany.
 */
export default function PastDueBanner() {
    const navigate = useNavigate();
    const { activeCompany } = useSharedData();

    const daysLeft = useMemo(() => {
        if (!activeCompany?.expected_block_at) return null;
        const block = new Date(`${activeCompany.expected_block_at}T00:00:00-05:00`);
        const diff = Math.ceil((block.getTime() - Date.now()) / (1000 * 60 * 60 * 24));
        return Math.max(0, diff);
    }, [activeCompany?.expected_block_at]);

    if (!activeCompany || activeCompany.status !== 'past_due') {
        return null;
    }

    const countdownText =
        daysLeft === null
            ? 'Regulariza el pago para evitar el bloqueo de tu cuenta.'
            : daysLeft === 0
              ? 'Tu cuenta entrará en bloqueo hoy si no se registra el pago.'
              : `Tu cuenta entrará en bloqueo en ${daysLeft} ${daysLeft === 1 ? 'día' : 'días'} si no se registra el pago.`;

    return (
        <div className="px-4 pt-4 sm:px-6 md:px-8">
            <Alert variant="warning">
                <AlertTriangle className="h-5 w-5" />
                <AlertTitle>Tu cuenta está en mora</AlertTitle>
                <AlertDescription className="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
                    <span>{countdownText}</span>
                    <Button size="sm" variant="outline" className="shrink-0" onClick={() => navigate(route('billing'))}>
                        Ir a Facturación
                    </Button>
                </AlertDescription>
            </Alert>
        </div>
    );
}
