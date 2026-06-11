/**
 * Persistencia del consentimiento de cookies/analytics (Habeas Data CO).
 *
 * Espeja el modelo del banner de flexyflow.co, adaptado a las categorías que
 * el panel realmente usa: **esenciales** (siempre activas, implícitas) +
 * **analíticas** (GA4). El panel NO carga pixels de marketing/ads (Meta,
 * Google Ads, TikTok, LinkedIn), así que esa categoría se omite.
 *
 * Se persiste en localStorage; el evento real de tracking lo controla Consent
 * Mode v2 vía `updateGa4Consent()` en `hooks/use-ga4.ts`.
 */

const STORAGE_KEY = 'flexyflow_consent';

/** Bump si cambian las categorías → re-pregunta a usuarios con consentimiento viejo. */
const CONSENT_VERSION = 1;

export interface ConsentState {
    version: number;
    /** Analíticas (GA4). Las esenciales son siempre `true` e implícitas. */
    analytics: boolean;
    /** ISO timestamp de la decisión (rastro Habeas Data). */
    decidedAt: string;
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
        if (parsed.version !== CONSENT_VERSION || typeof parsed.analytics !== 'boolean') {
            return null;
        }
        return parsed as ConsentState;
    } catch {
        return null;
    }
}

/** Persiste la decisión y la devuelve. Falla silencioso si localStorage no está. */
export function setStoredConsent(analytics: boolean): ConsentState {
    const state: ConsentState = {
        version: CONSENT_VERSION,
        analytics,
        decidedAt: new Date().toISOString(),
    };
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch {
        // localStorage no disponible (modo privado): el consentimiento dura la sesión.
    }
    return state;
}
