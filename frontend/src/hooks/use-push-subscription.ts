import { useSharedData } from '@/lib/shared-data';
import { useCallback, useEffect, useState } from 'react';

/**
 * Hook que gestiona la suscripción Web Push del dispositivo actual (#149).
 *
 * Encapsula el handshake browser ↔ backend:
 *   1. `serviceWorker.ready` → obtiene el SW registrado.
 *   2. `PushManager.subscribe({ applicationServerKey: vapid })` → pide
 *      permiso al usuario y arma la sub local.
 *   3. POST `/api/v1/push/subscriptions` con `{ endpoint, p256dh, auth }`.
 *   4. `getSubscription()` se re-consulta para reflejar el estado real.
 *
 * Diseñado para usarse desde React; no hace daño llamarlo desde varias
 * páginas simultáneas — internamente sincroniza con el browser API.
 *
 * Browser support (Mayo 2026):
 *  - Chrome / Edge / Firefox / Opera desktop+Android: total.
 *  - Safari iOS 16.4+: solo si la PWA está instalada (Add to Home Screen).
 *  - Safari macOS 16.4+: total.
 *  - iOS <16.4: NO soportado. `isSupported = false` evita mostrar UI.
 */
export interface UsePushSubscriptionReturn {
    isSupported: boolean;
    permission: NotificationPermission;
    isSubscribed: boolean;
    isStandalone: boolean;
    busy: boolean;
    error: string | null;
    subscribe: () => Promise<void>;
    unsubscribe: () => Promise<void>;
    refresh: () => Promise<void>;
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const output = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) {
        output[i] = rawData.charCodeAt(i);
    }
    return output;
}

function arrayBufferToBase64Url(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function detectStandalone(): boolean {
    if (typeof window === 'undefined') return false;
    const media = window.matchMedia('(display-mode: standalone)').matches;
    const iosStandalone = 'standalone' in window.navigator && (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
    return media || iosStandalone;
}

export function usePushSubscription(): UsePushSubscriptionReturn {
    const { vapidPublicKey } = useSharedData();

    const [permission, setPermission] = useState<NotificationPermission>('default');
    const [isSubscribed, setIsSubscribed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const isSupported =
        typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && typeof Notification !== 'undefined';

    const isStandalone = detectStandalone();

    const refresh = useCallback(async () => {
        if (!isSupported) return;
        try {
            setPermission(Notification.permission);
            const reg = await navigator.serviceWorker.getRegistration();
            const sub = (await reg?.pushManager.getSubscription()) ?? null;
            setIsSubscribed(sub !== null);
        } catch {
            setIsSubscribed(false);
        }
    }, [isSupported]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    const subscribe = useCallback(async () => {
        if (!isSupported) {
            setError('Tu navegador no soporta notificaciones push.');
            return;
        }
        if (!vapidPublicKey) {
            setError('Configuración de notificaciones no disponible.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const reg = await navigator.serviceWorker.ready;

            const perm = await Notification.requestPermission();
            setPermission(perm);
            if (perm !== 'granted') {
                setError('Bloqueaste las notificaciones. Habilítalas desde la configuración del navegador.');
                return;
            }

            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });

            const p256dhBuf = sub.getKey('p256dh');
            const authBuf = sub.getKey('auth');
            if (!p256dhBuf || !authBuf) {
                setError('No pudimos completar la suscripción. Intenta de nuevo.');
                return;
            }

            const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

            // `credentials: include` (no `same-origin`): en PDN la API vive en
            // otro host (cross-origin same-site) y la cookie HttpOnly del JWT
            // sólo viaja si se pide explícitamente. En dev (proxy de Vite) es
            // inofensivo. CORS lo habilita con supports_credentials=true.
            const response = await fetch('/api/v1/push/subscriptions', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    endpoint: sub.endpoint,
                    p256dh: arrayBufferToBase64Url(p256dhBuf),
                    auth: arrayBufferToBase64Url(authBuf),
                    user_agent: navigator.userAgent,
                }),
            });

            if (!response.ok) {
                setError('No pudimos registrar la suscripción en el servidor.');
                await sub.unsubscribe().catch(() => undefined);
                return;
            }

            setIsSubscribed(true);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Error inesperado activando notificaciones.');
        } finally {
            setBusy(false);
        }
    }, [isSupported, vapidPublicKey]);

    const unsubscribe = useCallback(async () => {
        if (!isSupported) return;
        setBusy(true);
        setError(null);
        try {
            const reg = await navigator.serviceWorker.getRegistration();
            const sub = (await reg?.pushManager.getSubscription()) ?? null;

            if (sub) {
                const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
                await fetch('/api/v1/push/subscriptions', {
                    method: 'DELETE',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ endpoint: sub.endpoint }),
                }).catch(() => undefined);

                await sub.unsubscribe().catch(() => undefined);
            }
            setIsSubscribed(false);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Error inesperado al quitar la suscripción.');
        } finally {
            setBusy(false);
        }
    }, [isSupported]);

    return {
        isSupported,
        permission,
        isSubscribed,
        isStandalone,
        busy,
        error,
        subscribe,
        unsubscribe,
        refresh,
    };
}
