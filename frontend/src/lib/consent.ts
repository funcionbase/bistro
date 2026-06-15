/**
 * Persistencia del consentimiento de cookies/analytics (Habeas Data CO).
 *
 * Espeja el modelo del banner de flexyflow.co: tres categorías —
 * **esenciales** (siempre activas, implícitas), **analíticas** (GTM + GA4) y
 * **marketing** (TikTok Pixel). Se persiste en localStorage; el efecto real de
 * tracking lo aplican los consumidores:
 * - analíticas → Consent Mode v2 vía `updateGa4Consent()` (`hooks/use-ga4.ts`).
 * - marketing  → carga del pixel vía `useTiktokPixel()` (`hooks/use-tiktok-pixel.ts`).
 *
 * Los consumidores se enteran de una decisión nueva (sin recargar) suscribiéndose
 * con `subscribeConsent()`; `setStoredConsent()` notifica a todos.
 */

const STORAGE_KEY = 'flexyflow_consent';

/** Bump si cambian las categorías → re-pregunta a usuarios con consentimiento viejo. */
const CONSENT_VERSION = 2;

export interface ConsentState {
    version: number;
    /** Analíticas (GTM + GA4). Las esenciales son siempre `true` e implícitas. */
    analytics: boolean;
    /** Marketing y publicidad (TikTok Pixel). */
    marketing: boolean;
    /** ISO timestamp de la decisión (rastro Habeas Data). */
    decidedAt: string;
}

/** Callback notificado cuando el usuario guarda una decisión. */
type ConsentListener = (state: ConsentState) => void;

const listeners = new Set<ConsentListener>();

/**
 * Suscribe un callback a los cambios de consentimiento. Devuelve la función de
 * baja. Útil para que un tracker ya montado (ej: el pixel de la landing) cargue
 * en cuanto el usuario acepta, sin recargar la página.
 */
export function subscribeConsent(listener: ConsentListener): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

/** Lee la decisión guardada. `null` ⇒ el usuario aún no decidió (mostrar banner). */
export function getStoredConsent(): ConsentState | null {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw) as Partial<ConsentState>;
        // Versión vieja o payload corrupto ⇒ re-preguntar.
        if (parsed.version !== CONSENT_VERSION || typeof parsed.analytics !== 'boolean' || typeof parsed.marketing !== 'boolean') {
            return null;
        }
        return parsed as ConsentState;
    } catch {
        return null;
    }
}

/**
 * Persiste la decisión, notifica a los suscriptores y la devuelve. Falla
 * silencioso si localStorage no está disponible (modo privado).
 */
export function setStoredConsent(analytics: boolean, marketing: boolean): ConsentState {
    const state: ConsentState = {
        version: CONSENT_VERSION,
        analytics,
        marketing,
        decidedAt: new Date().toISOString(),
    };
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch {
        // localStorage no disponible (modo privado): el consentimiento dura la sesión.
    }
    listeners.forEach((listener) => listener(state));
    return state;
}
