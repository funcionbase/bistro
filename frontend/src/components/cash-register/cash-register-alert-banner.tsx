import { AppLink } from '@/components/app-link';
import { useCashRegister } from '@/hooks/use-cash-register';
import { useToken } from '@/hooks/use-token';
import { AlertTriangle, ArrowRight, Lock } from 'lucide-react';

/**
 * Banner global persistente: aparece en TODA la app autenticada cuando la caja
 * está cerrada y la empresa DEBERÍA estar operando (horario hábil + menú
 * activo). Mientras el banner sea visible:
 *  - El menú público devuelve restaurant_status.is_open = false (clientes no
 *    pueden ordenar).
 *  - Las acciones financieras del panel (crear orden, cobrar, agregar a mesa,
 *    devolución) están bloqueadas en backend.
 *
 * El banner es persistente (no dismissible): es información operativa crítica
 * y se autoresuelve al abrir caja.
 */
export default function CashRegisterAlertBanner() {
    const token = useToken();
    // shouldAlert ya considera multi-caja: true solo si NINGUNA caja está abierta.
    const { shouldAlert } = useCashRegister(token);

    if (!shouldAlert) {
        return null;
    }

    return (
        <div
            role="alert"
            className="border-b border-[color:var(--color-status-warning)]/40 bg-[color:var(--color-status-warning)]/10 px-3 py-2 text-sm text-[color:var(--color-status-warning)] shadow-sm"
        >
            <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-2">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                <div className="text-foreground min-w-0 flex-1">
                    <span className="font-semibold text-[color:var(--color-status-warning)]">Caja cerrada.</span>{' '}
                    <span className="text-foreground/80">
                        La empresa está en horario hábil con menú activo, pero los clientes no pueden ordenar hasta que abras la caja.
                    </span>
                </div>
                <AppLink
                    href="/orders/cashier"
                    className="text-background inline-flex items-center gap-1 rounded-md bg-[color:var(--color-status-warning)] px-3 py-1 text-xs font-semibold hover:bg-[color:var(--color-status-warning)]/90"
                >
                    <Lock className="h-3 w-3" />
                    Abrir caja
                    <ArrowRight className="h-3 w-3" />
                </AppLink>
            </div>
        </div>
    );
}
