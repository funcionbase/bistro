import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { usePushSubscription } from '@/hooks/use-push-subscription';
import { Bell, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

/**
 * Banner discreto que invita al usuario a activar notificaciones push
 * (#149). Se muestra al cargar el dashboard SOLO si:
 *  - El navegador soporta Web Push (`isSupported`).
 *  - La PWA está instalada (`isStandalone`) — fuera del browser tab.
 *  - El permiso es `default` (ni granted ni denied).
 *  - El usuario no lo descartó en los últimos 7 días (localStorage).
 *
 * El banner aplica DS v3.1: `<Alert variant="info">`, tokens semánticos,
 * full-width mobile, max-w-md desktop. Botones apilados en mobile, en
 * línea en >=640px.
 */
const DISMISS_KEY = 'pwa.push.prompt.dismissed_until';
const DISMISS_DAYS = 7;

export function PushPromptBanner() {
    const { isSupported, isStandalone, permission, isSubscribed, busy, subscribe } = usePushSubscription();
    const [dismissedClient, setDismissedClient] = useState(false);

    const isDismissed = useMemo(() => {
        if (dismissedClient) return true;
        if (typeof window === 'undefined') return false;
        try {
            const until = window.localStorage.getItem(DISMISS_KEY);
            if (!until) return false;
            const ts = Number.parseInt(until, 10);
            return Number.isFinite(ts) && ts > Date.now();
        } catch {
            return false;
        }
    }, [dismissedClient]);

    useEffect(() => {
        if (permission === 'granted' || isSubscribed) {
            // Si el usuario ya aceptó por otro flujo, limpiamos la marca.
            try {
                window.localStorage.removeItem(DISMISS_KEY);
            } catch {
                /* ignore */
            }
        }
    }, [permission, isSubscribed]);

    if (!isSupported || !isStandalone) return null;
    if (permission !== 'default') return null;
    if (isSubscribed) return null;
    if (isDismissed) return null;

    const handleDismiss = () => {
        try {
            const until = Date.now() + DISMISS_DAYS * 24 * 60 * 60 * 1000;
            window.localStorage.setItem(DISMISS_KEY, String(until));
        } catch {
            /* ignore */
        }
        setDismissedClient(true);
    };

    return (
        <Alert variant="accent" className="relative pr-10">
            <Bell className="h-4 w-4" />
            <AlertTitle>Recibí avisos sin tener la app abierta</AlertTitle>
            <AlertDescription className="space-y-3">
                <p className="text-sm">
                    Activa las notificaciones push y te avisamos cuando haya pedidos pendientes de aprobación o novedades de inventario. Funciona
                    aunque tengas la app cerrada.
                </p>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button type="button" size="sm" onClick={() => void subscribe()} disabled={busy}>
                        {busy ? 'Activando…' : 'Activar notificaciones'}
                    </Button>
                    <Button type="button" size="sm" variant="outline" onClick={handleDismiss} disabled={busy}>
                        Más tarde
                    </Button>
                </div>
            </AlertDescription>
            <button
                type="button"
                onClick={handleDismiss}
                aria-label="Cerrar este aviso"
                className="text-muted-foreground hover:text-foreground absolute top-2 right-2 inline-flex h-7 w-7 items-center justify-center rounded-md transition-colors"
            >
                <X className="h-4 w-4" />
            </button>
        </Alert>
    );
}

export default PushPromptBanner;
