import { useIsStandalone } from '@/hooks/use-is-standalone';
import { useIsMobile } from '@/hooks/use-mobile';
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
 *
 * Segmentación de tráfico (`traffic_type`):
 * - Distingue navegador vs PWA instalada (`useIsStandalone`, ya usado en el
 *   resto de la app para adaptar UI) cruzado con mobile vs desktop
 *   (`useIsMobile`, breakpoint 768px — el mismo que usa el resto del DS).
 *   Reusa ambos hooks existentes en vez de reinventar detección.
 * - Se manda como GA4 **user property** (`gtag('set', 'user_properties', …)`)
 *   — aplica automáticamente a TODOS los eventos posteriores de la sesión
 *   (page_view, cta_click vía `lib/analytics.ts`, cualquier evento futuro)
 *   sin tener que tocar cada call site. También viaja como parámetro directo
 *   en cada `page_view` para poder filtrarlo en GA4 Explore sin esperar a
 *   que el custom dimension user-scoped propague.
 * - Para que aparezca en informes estándar de GA4 hay que registrar
 *   `traffic_type` como Custom Dimension (Admin → Definiciones personalizadas)
 *   — paso manual en la consola de GA4, no en código.
 */

/** Los 4 buckets de tráfico que pidió el negocio: navegador/PWA × desktop/mobile. */
export type TrafficType = 'web_desktop' | 'web_mobile' | 'pwa_desktop' | 'pwa_mobile';

export function resolveTrafficType(isMobile: boolean, isStandalone: boolean): TrafficType {
    if (isStandalone) {
        return isMobile ? 'pwa_mobile' : 'pwa_desktop';
    }
    return isMobile ? 'web_mobile' : 'web_desktop';
}

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
    const isMobile = useIsMobile();
    const isStandalone = useIsStandalone();
    const trafficType = resolveTrafficType(isMobile, isStandalone);
    const resolvedId = resolveGa4Id(measurementId);
    const enabled = Boolean(resolvedId);

    // Init: carga diferida + consent default + user property de tráfico.
    // Depende de `trafficType` además de `resolvedId`: si el dispositivo
    // cruza el breakpoint mobile/desktop o cambia de modo standalone durante
    // la sesión (resize de ventana, instalación de la PWA en caliente),
    // re-emite el user property actualizado — `loadGtag` es idempotente
    // (guard de módulo), así que re-correr el effect no reinyecta el script.
    useEffect(() => {
        if (!resolvedId) {
            return;
        }
        loadGtag(resolvedId);
        if (typeof window.gtag === 'function') {
            window.gtag('set', 'user_properties', { traffic_type: trafficType });
        }
        // Usuario que ya aceptó analíticas en una visita previa: re-otorga el
        // consentimiento (el banner solo aparece cuando NO hay decisión guardada).
        if (getStoredConsent()?.analytics) {
            updateGa4Consent(true);
        }
    }, [resolvedId, trafficType]);

    // `page_view` manual en cada cambio de ruta de React Router. `traffic_type`
    // viaja también acá (no solo como user property) para poder filtrarlo de
    // inmediato en GA4 Explore como custom dimension de evento.
    useEffect(() => {
        if (!enabled || typeof window.gtag !== 'function') {
            return;
        }
        const pagePath = redactPath(location.pathname);
        window.gtag('event', 'page_view', {
            page_path: pagePath,
            page_location: window.location.origin + pagePath,
            traffic_type: trafficType,
            page_title: document.title,
        });
    }, [enabled, location.pathname, trafficType]);
}
