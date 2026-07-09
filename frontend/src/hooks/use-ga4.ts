import { getStoredConsent } from '@/lib/consent';
import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Integración de Google Analytics 4 para la SPA.
 *
 * Por qué un hook y no un `<script>` suelto: la app es un SPA React Router
 * (sin Inertia ni recarga de página), así que gtag.js solo vería el primer
 * pageview. Disparamos `page_view` manualmente en cada navegación.
 *
 * Privacidad (Habeas Data CO):
 * - Consent Mode v2 arranca en `denied` por defecto → GA carga sin cookies y
 *   solo envía pings cookieless hasta que haya aceptación (banner = fase
 *   aparte, ver `updateGa4Consent`).
 * - NO se envía PII ni identificadores sensibles (emails, NITs, nombres). El
 *   `page_path` se redacta (IDs numéricos/uuid → `:id`) para no filtrar
 *   identificadores de entidad ni inflar reportes con paths únicos.
 */

declare global {
    interface Window {
        dataLayer?: unknown[];
    }
}

/** Estado de consentimiento de Consent Mode v2. */
type ConsentState = 'granted' | 'denied';

/** Guard de init: gtag.js se inyecta una sola vez por vida de la pestaña. */
let gaInitialized = false;

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const NUMERIC_RE = /^\d+$/;

/**
 * Redacta identificadores de los segmentos de ruta para no enviar IDs de
 * entidad (PK numérica, uuid, NIT, branch_id) a GA4. Conserva la estructura
 * para reportes agregados: `/clients/<uuid>` → `/clients/:id`.
 */
function redactPath(pathname: string): string {
    return pathname
        .split('/')
        .map((segment) => (UUID_RE.test(segment) || NUMERIC_RE.test(segment) ? ':id' : segment))
        .join('/');
}

/**
 * Carga diferida de gtag.js + Consent Mode v2 + config sin pageview
 * automático. Idempotente: corre una sola vez.
 */
function loadGtag(measurementId: string): void {
    if (gaInitialized) {
        return;
    }
    gaInitialized = true;

    const dataLayer = (window.dataLayer = window.dataLayer ?? []);
    // gtag.js procesa la cola de `dataLayer` SOLO cuando cada comando se empuja
    // como el objeto `arguments` nativo (array-like). Empujar un Array real
    // (lo que produciría `(...args) => dataLayer.push(args)`) hace que gtag.js
    // ignore el comando en silencio ⇒ cero tracking pese a ID y config OK.
    // Por eso NO usamos rest-params acá: replicamos el snippet canónico de
    // Google al pie de la letra.
    type Gtag = (command: string, ...args: unknown[]) => void;
    const gtag: Gtag = function gtag(): void {
        // eslint-disable-next-line prefer-rest-params
        dataLayer.push(arguments);
    };
    window.gtag = gtag;

    // 1. Consent Mode v2 en `denied` ANTES de cualquier otra llamada.
    const consentDefaults: Record<string, ConsentState | number> = {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
        // Espera breve por una decisión de consentimiento antes de disparar
        // tags, para no perder el primer hit si el banner resuelve rápido.
        wait_for_update: 500,
    };
    gtag('consent', 'default', consentDefaults);

    // 2. Timestamp de init (formato canónico del snippet de Google).
    gtag('js', new Date());

    // 3. Config sin `page_view` automático: lo disparamos manual por navegación.
    gtag('config', measurementId, { send_page_view: false });

    // 4. gtag.js diferido: `async` + inyección post-mount (useEffect) ⇒ no
    //    bloquea el render. La cola de `dataLayer` se procesa al cargar.
    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`;
    document.head.appendChild(script);
}

/**
 * Actualiza el consentimiento tras la decisión del usuario. La invocará el
 * banner de consentimiento (fase aparte). `granted=true` habilita analytics +
 * ads storage; `false` los mantiene denegados. No-op si GA4 no está cargado.
 */
export function updateGa4Consent(granted: boolean): void {
    if (typeof window.gtag !== 'function') {
        return;
    }
    const state: ConsentState = granted ? 'granted' : 'denied';
    window.gtag('consent', 'update', {
        ad_storage: state,
        ad_user_data: state,
        ad_personalization: state,
        analytics_storage: state,
    });
}

/**
 * Resuelve el Measurement ID efectivo: el bootstrap (runtime, fuente primaria)
 * gana; si viene vacío, cae al fallback build-time `VITE_GA4_ID`. `null` si
 * ninguno está configurado (local/qa) ⇒ GA4 deshabilitado.
 */
export function resolveGa4Id(bootstrapId: string | null | undefined): string | null {
    return bootstrapId || (import.meta.env.VITE_GA4_ID as string | undefined) || null;
}

/**
 * Engancha GA4 al ciclo de vida de la SPA. Montar UNA vez en el árbol (lo
 * hace `RootRoute` en `spa/router.tsx`, cubriendo públicas + panel).
 *
 * @param measurementId Measurement ID `G-XXXXXXXXXX` runtime (tiene
 *   precedencia si se pasa). Con `null`/vacío cae al fallback build-time
 *   `VITE_GA4_ID` horneado en el bundle — el caso actual: la route raíz no
 *   tiene bootstrap. Si ambos están vacíos (local/qa) ⇒ no-op total: no se
 *   inyecta gtag.js ni se envía nada a Google.
 */
export function useGa4(measurementId: string | null | undefined): void {
    const location = useLocation();
    const resolvedId = resolveGa4Id(measurementId);
    const enabled = Boolean(resolvedId);

    // Init: carga diferida + consent default. Corre cuando hay ID válido.
    useEffect(() => {
        if (!resolvedId) {
            return;
        }
        loadGtag(resolvedId);
        // Usuario que ya aceptó analíticas en una visita previa: re-otorga el
        // consentimiento (el banner solo aparece cuando NO hay decisión guardada).
        if (getStoredConsent()?.analytics) {
            updateGa4Consent(true);
        }
    }, [resolvedId]);

    // `page_view` manual en cada cambio de ruta de React Router.
    useEffect(() => {
        if (!enabled || typeof window.gtag !== 'function') {
            return;
        }
        const pagePath = redactPath(location.pathname);
        window.gtag('event', 'page_view', {
            page_path: pagePath,
            page_location: window.location.origin + pagePath,
            page_title: document.title,
        });
    }, [enabled, location.pathname]);
}
