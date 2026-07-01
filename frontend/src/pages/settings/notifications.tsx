import HeadingSmall from '@/components/heading-small';
import PushSubscriptionsList from '@/components/notifications/push-subscriptions-list';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { usePushSubscription } from '@/hooks/use-push-subscription';
import SettingsLayout from '@/layouts/settings/layout';

import { Bell, BellOff, ShieldAlert, Smartphone } from 'lucide-react';


/**
 * Página `/settings/notifications` (#149 CA4).
 *
 * Permite al usuario:
 *  - Ver el estado actual de notificaciones push (Activadas / Bloqueadas /
 *    No soportado / Sin permiso).
 *  - Activar la suscripción del dispositivo actual.
 *  - Listar y revocar dispositivos suscritos.
 *
 * Mobile-first: el card de estado y la lista de devices apilan vertical
 * con tokens del DS (`bg-card`, `border-border`, `text-muted-foreground`).
 * Browser support claro: si `isSupported = false`, se muestra explanación
 * en lugar de botón muerto.
 */
export default function NotificationsSettings() {
    const { isSupported, isStandalone, permission, isSubscribed, busy, subscribe, unsubscribe, error, refresh } = usePushSubscription();

    return (
        <PageShell title="Notificaciones">
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Notificaciones push" description="Recibí avisos del sistema operativo incluso con la app cerrada." />

                    <StatusCard
                        isSupported={isSupported}
                        isStandalone={isStandalone}
                        permission={permission}
                        isSubscribed={isSubscribed}
                        busy={busy}
                        error={error}
                        onSubscribe={subscribe}
                        onUnsubscribe={unsubscribe}
                    />

                    <section className="space-y-3">
                        <div>
                            <h3 className="text-foreground text-base font-semibold">Dispositivos suscritos</h3>
                            <p className="text-muted-foreground text-sm">
                                Mostramos los dispositivos donde activaste notificaciones. Podés quitar el dispositivo actual desde acá; los demás se
                                quitan abriendo la app en ese dispositivo.
                            </p>
                        </div>
                        <PushSubscriptionsList onLocalRemoved={() => void refresh()} />
                    </section>
                </div>
            </SettingsLayout>
        </PageShell>
    );
}

interface StatusProps {
    isSupported: boolean;
    isStandalone: boolean;
    permission: NotificationPermission;
    isSubscribed: boolean;
    busy: boolean;
    error: string | null;
    onSubscribe: () => Promise<void>;
    onUnsubscribe: () => Promise<void>;
}

function StatusCard({ isSupported, isStandalone, permission, isSubscribed, busy, error, onSubscribe, onUnsubscribe }: StatusProps) {
    if (!isSupported) {
        return (
            <Alert variant="warning">
                <ShieldAlert className="h-4 w-4" />
                <AlertTitle>Tu navegador no soporta notificaciones push</AlertTitle>
                <AlertDescription>
                    Web Push requiere Chrome, Edge, Firefox o Safari 16.4+. En iOS también necesitás instalar la app desde "Compartir → Añadir a
                    inicio".
                </AlertDescription>
            </Alert>
        );
    }

    if (!isStandalone) {
        return (
            <Alert variant="accent">
                <Smartphone className="h-4 w-4" />
                <AlertTitle>Instala la app para recibir notificaciones</AlertTitle>
                <AlertDescription>
                    En móvil toca "Compartir → Añadir a pantalla de inicio". En desktop usa "Instalar bistro" desde el menú del navegador. Una vez
                    instalada, vuelve acá para activar.
                </AlertDescription>
            </Alert>
        );
    }

    if (permission === 'denied') {
        return (
            <Alert variant="critical">
                <BellOff className="h-4 w-4" />
                <AlertTitle>Notificaciones bloqueadas</AlertTitle>
                <AlertDescription>
                    Bloqueaste las notificaciones para este sitio. Para reactivarlas, abre la configuración del navegador y permite las
                    notificaciones, luego vuelve acá.
                </AlertDescription>
            </Alert>
        );
    }

    if (isSubscribed) {
        return (
            <Alert variant="safe">
                <Bell className="h-4 w-4" />
                <AlertTitle>Notificaciones activadas</AlertTitle>
                <AlertDescription className="space-y-3">
                    <p>Recibís push de pedidos pendientes y novedades de inventario en este dispositivo.</p>
                    {error && <p className="text-critical text-sm">{error}</p>}
                    <Button type="button" variant="outline" size="sm" onClick={() => void onUnsubscribe()} disabled={busy}>
                        {busy ? 'Quitando…' : 'Quitar este dispositivo'}
                    </Button>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Alert variant="accent">
            <Bell className="h-4 w-4" />
            <AlertTitle>Activa las notificaciones push</AlertTitle>
            <AlertDescription className="space-y-3">
                <p>Te avisamos cuando hay pedidos pendientes de aprobación o alertas de inventario, incluso con la app cerrada.</p>
                {error && <p className="text-critical text-sm">{error}</p>}
                <Button type="button" size="sm" onClick={() => void onSubscribe()} disabled={busy}>
                    {busy ? 'Activando…' : 'Activar notificaciones'}
                </Button>
            </AlertDescription>
        </Alert>
    );
}
