/**
 * Helper de tracking analitico para la plataforma.
 *
 * Convencion (heredada de la guia marketing v2.1, ver
 * application/FRONTEND_UI_GUIDELINES.md §16):
 *
 *   <Button data-cta="abrir-caja" data-cta-location="dashboard">
 *
 * Los CTAs con `data-cta` se rastrean via el listener global expuesto en
 * `attachCtaListener()`, que dispara `track('cta_click', {...})`.
 *
 * Estados del helper:
 * - gtag.js NO cargado (sin GA4_MEASUREMENT_ID en backend, ej. local/qa):
 *   no-op en produccion, console.debug en dev (visibilidad sin enviar nada).
 * - gtag.js cargado (lo inicializa `hooks/use-ga4.ts` con el ID del bootstrap):
 *   dispara `gtag('event', name, params)`. El carga/init del script y el
 *   Consent Mode v2 viven en `useGa4()`, NO aca: este helper solo emite eventos.
 */

declare global {
    interface Window {
        gtag?: (command: string, ...args: unknown[]) => void;
    }
}

const isDev = import.meta.env.DEV;

export type TrackParams = Record<string, string | number | boolean | null | undefined>;

/**
 * Dispara un evento analitico. Falla silencioso si no hay backend de
 * tracking disponible (no rompe la UI).
 */
export function track(eventName: string, params: TrackParams = {}): void {
    const enriched: TrackParams = {
        ...params,
        page_path: typeof window !== 'undefined' ? window.location.pathname : undefined,
    };

    if (isDev) {
        console.debug('[analytics]', eventName, enriched);
    }

    if (typeof window !== 'undefined' && typeof window.gtag === 'function') {
        window.gtag('event', eventName, enriched);
    }
}

/**
 * Listener global de clicks que captura cualquier elemento con
 * `data-cta` y dispara `cta_click`. Llamar una vez en el bootstrap
 * de la app (ver `spa/main.tsx`).
 */
export function attachCtaListener(): () => void {
    const handler = (event: MouseEvent) => {
        const target = event.target as HTMLElement | null;
        if (!target) {
            return;
        }
        const cta = target.closest<HTMLElement>('[data-cta]');
        if (!cta) {
            return;
        }
        track('cta_click', {
            cta_id: cta.dataset.cta,
            cta_location: cta.dataset.ctaLocation,
            anchor_text: cta.textContent?.trim().slice(0, 80),
        });
    };

    document.addEventListener('click', handler, { capture: true });
    return () => document.removeEventListener('click', handler, { capture: true });
}
