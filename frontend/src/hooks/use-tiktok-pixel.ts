import { useEffect } from 'react';

/**
 * Integración del TikTok Pixel para la SPA.
 *
 * Alcance deliberadamente acotado: este hook se monta SOLO en la landing
 * pública (`/` → `pages/welcome.tsx`), no en el árbol global. El pixel mide
 * visitas/conversiones de campañas de TikTok Ads que aterrizan en la portada;
 * el resto del panel (rutas autenticadas, carta pública, KDS) NO lo carga.
 *
 * Privacidad (Habeas Data CO): por decisión explícita del producto este pixel
 * carga sin gate de consentimiento. A diferencia de GA4 (Consent Mode v2 en
 * `denied` por defecto, ver `hooks/use-ga4.ts`), TikTok dispara `page` apenas
 * monta la landing. Si a futuro se exige consentimiento de marketing, hay que
 * envolver `loadTiktokPixel` tras la decisión del banner (`lib/consent.ts`).
 *
 * El SDK se inyecta una sola vez por vida de la pestaña y solo cuando hay un
 * Pixel ID configurado (build de pdn). En local/qa queda inerte para no
 * contaminar los reportes de TikTok con tráfico de desarrollo.
 */

/** Cola/stub del SDK de TikTok (`ttq`). Tipado laxo: el SDK la sobrescribe al cargar. */
interface TiktokQueue {
    page: (...args: unknown[]) => void;
    track: (...args: unknown[]) => void;
    load: (pixelId: string, options?: Record<string, unknown>) => void;
    [key: string]: unknown;
}

declare global {
    interface Window {
        TiktokAnalyticsObject?: string;
        ttq?: TiktokQueue;
    }
}

/** Guard de init: el SDK se inyecta una sola vez por vida de la pestaña. */
let pixelInitialized = false;

/**
 * Inyecta el snippet base oficial del TikTok Pixel (Events API) y dispara el
 * primer `page`. Réplica fiel del bootstrap canónico de TikTok (define el stub
 * `ttq` que encola llamadas hasta que `events.js` carga async). Idempotente.
 */
function loadTiktokPixel(pixelId: string): void {
    if (pixelInitialized) {
        return;
    }
    pixelInitialized = true;

    // Snippet base canónico de TikTok. Se reproduce al pie de la letra (con
    // tipos laxos) porque el SDK depende de esta forma exacta del stub: una
    // cola array-like que `events.js` consume al cargar. Reescribirlo "limpio"
    // rompe el contrato del SDK igual que pasaría con gtag.js.
    /* eslint-disable @typescript-eslint/no-explicit-any, prefer-rest-params */
    (function (w: any, d: Document, t: string) {
        w.TiktokAnalyticsObject = t;
        const ttq = (w[t] = w[t] || []);
        ttq.methods = [
            'page',
            'track',
            'identify',
            'instances',
            'debug',
            'on',
            'off',
            'once',
            'ready',
            'alias',
            'group',
            'enableCookie',
            'disableCookie',
            'holdConsent',
            'revokeConsent',
            'grantConsent',
        ];
        ttq.setAndDefer = function (obj: any, method: string) {
            obj[method] = function () {
                obj.push([method].concat(Array.prototype.slice.call(arguments, 0)));
            };
        };
        for (let i = 0; i < ttq.methods.length; i++) {
            ttq.setAndDefer(ttq, ttq.methods[i]);
        }
        ttq.instance = function (id: string) {
            const inst = ttq._i[id] || [];
            for (let n = 0; n < ttq.methods.length; n++) {
                ttq.setAndDefer(inst, ttq.methods[n]);
            }
            return inst;
        };
        ttq.load = function (id: string, options?: Record<string, unknown>) {
            const url = 'https://analytics.tiktok.com/i18n/pixel/events.js';
            const partner = options && (options as { partner?: unknown }).partner;
            ttq._i = ttq._i || {};
            ttq._i[id] = [];
            ttq._i[id]._u = url;
            ttq._t = ttq._t || {};
            ttq._t[id] = +new Date();
            ttq._o = ttq._o || {};
            ttq._o[id] = options || {};
            const script = d.createElement('script');
            script.type = 'text/javascript';
            script.async = true;
            script.src = url + '?sdkid=' + id + '&lib=' + t;
            const first = d.getElementsByTagName('script')[0];
            first.parentNode?.insertBefore(script, first);
            void partner;
        };

        ttq.load(pixelId);
        ttq.page();
    })(window, document, 'ttq');
    /* eslint-enable @typescript-eslint/no-explicit-any, prefer-rest-params */
}

/**
 * Resuelve el Pixel ID efectivo. Solo build-time (`VITE_TIKTOK_PIXEL_ID`),
 * horneado en el bundle de pdn. `null` si está vacío (local/qa) ⇒ pixel
 * deshabilitado.
 */
export function resolveTiktokPixelId(): string | null {
    return (import.meta.env.VITE_TIKTOK_PIXEL_ID as string | undefined) || null;
}

/**
 * Engancha el TikTok Pixel a la landing. Montar UNA vez, solo en `Welcome`.
 * No-op si no hay Pixel ID configurado (local/qa) ⇒ no inyecta `events.js`.
 */
export function useTiktokPixel(): void {
    useEffect(() => {
        const pixelId = resolveTiktokPixelId();
        if (!pixelId) {
            return;
        }
        loadTiktokPixel(pixelId);
    }, []);
}
