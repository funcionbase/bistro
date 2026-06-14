import { useToast } from '@/components/ui/toast';
import { apiFetch } from '@/lib/api';
import { useEffect, useRef } from 'react';

/**
 * Avisa al usuario que disparó un cambio de estado cuando el SMS al cliente
 * falló en el envío async (#275 Fase 4). El backend (OrderSmsFailureController)
 * devuelve SOLO los fallos del propio usuario aún no vistos; tras mostrarlos
 * hacemos ack (`/seen`) para que no se repitan — el "una sola vez" lo garantiza
 * el servidor (idempotente entre dispositivos y N instancias).
 *
 * El cambio en el tablero/cobro NUNCA depende de esto: el SMS es best-effort y
 * se envía fuera de la transacción. Esto es puramente feedback informativo.
 *
 * Montado una vez en el layout autenticado (dentro de ToastProvider), así cubre
 * al usuario haga la acción donde la haga (board o cierre de caja).
 */
const POLL_MS = 45_000;

interface SmsFailure {
    id: string;
    order_id: string;
    order_code: string | null;
    to_status: string;
    created_at: string | null;
}

export default function OrderSmsFailureWatcher() {
    const { showToast } = useToast();
    // Evita re-toast dentro de la misma sesión antes de que el ack propague.
    const shown = useRef<Set<string>>(new Set());

    useEffect(() => {
        let active = true;
        let timer: ReturnType<typeof setTimeout> | undefined;

        const poll = async (): Promise<void> => {
            // No molestar mientras la pestaña está oculta.
            if (typeof document !== 'undefined' && document.hidden) {
                return;
            }
            try {
                const res = await apiFetch('/api/v1/order-sms-failures');
                if (!active || !res.ok) return;

                const json = (await res.json().catch(() => ({}))) as { data?: SmsFailure[] };
                const fresh = (json.data ?? []).filter((f) => !shown.current.has(f.id));
                if (fresh.length === 0) return;

                for (const failure of fresh) {
                    shown.current.add(failure.id);
                    const ref = failure.order_code ? `#${failure.order_code}` : 'al cliente';
                    showToast(
                        'error',
                        `No se pudo enviar el SMS de notificación ${ref}. El cambio se guardó igual.`,
                        6000,
                    );
                }

                // Ack server-side: no vuelven a aparecer (ni en otro dispositivo).
                await apiFetch('/api/v1/order-sms-failures/seen', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: fresh.map((f) => f.id) }),
                }).catch(() => undefined);
            } catch {
                // best-effort: un fallo de red no rompe nada, reintenta al próximo tick.
            }
        };

        const tick = (): void => {
            void poll().finally(() => {
                if (active) timer = setTimeout(tick, POLL_MS);
            });
        };

        tick();

        return () => {
            active = false;
            if (timer) clearTimeout(timer);
        };
    }, [showToast]);

    return null;
}
