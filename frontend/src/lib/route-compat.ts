import { ROUTE_MAP } from './route-map';

/**
 * Resolver de rutas con nombre para el shell SPA.
 *
 * Resuelve nombres de ruta contra `ROUTE_MAP` — el mapa autogenerado desde
 * `php artisan route:list`. Reemplaza al `route()` global de Ziggy.
 *
 * Dos helpers:
 *  - `route(name, params)` devuelve un path relativo (ej. `/dashboard`). Apto
 *    para `<a href>` y navegación a rutas del SPA (las sirve el Worker).
 *  - `routeBackend(name, params)` prefija con `VITE_API_URL` para que el
 *    `<a href>` apunte al backend Laravel cross-origin (ej. el flujo OAuth de
 *    Google: el botón debe ir DIRECTO a `bistro-api.example.com/auth/google`,
 *    no a una ruta inexistente del Worker SPA).
 *
 * Cuándo usar `routeBackend` en vez de `route`:
 *  - SOLO cuando el `<a href>` (o `window.location.href = ...`) navega top-level
 *    a un endpoint que vive en el backend (`/auth/google`, `/auth/google/callback`,
 *    `/storage-proxy/...` cuando se sirve como descarga directa).
 *  - Las llamadas vía `apiFetch` ya tienen su propio prefijo (`src/lib/api.ts`),
 *    NO usar `routeBackend` ahí.
 */

type RouteParams = Record<string, string | number> | undefined;

/**
 * Host del backend Laravel. En dev queda vacío → paths relativos que resuelve
 * el proxy de Vite (ver `vite.config.ts` server.proxy). En PDN/QA viene de
 * `VITE_API_URL` (ej. `https://bistro-api.example.com`).
 *
 * Definida fuera de la función para no recalcularse en cada llamada.
 */
const BACKEND_BASE_URL: string = import.meta.env.VITE_API_URL ?? '';

function fillTemplate(template: string, params: RouteParams): string {
    return template.replace(/\{([^}?]+)\??\}/g, (_, key: string) => {
        const value = params?.[key];
        return value != null ? encodeURIComponent(String(value)) : '';
    });
}

/**
 * Resuelve un nombre de ruta a su URL relativa.
 *
 * @param name   Nombre de la ruta (ej. 'dashboard', 'orders.cashier').
 * @param params Parámetros de la ruta (ej. { id: 42 }).
 */
export function route(name: string, params?: RouteParams): string {
    const template = ROUTE_MAP[name];
    if (template === undefined) {
        if (import.meta.env.DEV) {
            console.warn(`[route-compat] Ruta desconocida: "${name}"`);
        }
        return '/';
    }
    return fillTemplate(template, params);
}

/**
 * Resuelve un nombre de ruta a su URL absoluta apuntando al backend.
 *
 * Usar SOLO para `<a href>` o navegación top-level que debe ir al backend
 * cross-origin (flujo OAuth, descargas firmadas que el backend sirve, etc.).
 * Para llamadas a la API (`fetch`/`XHR`) usá `apiFetch` que ya prefija solo.
 *
 * @param name   Nombre de la ruta (ej. 'auth.google').
 * @param params Parámetros de la ruta.
 */
export function routeBackend(name: string, params?: RouteParams): string {
    return `${BACKEND_BASE_URL}${route(name, params)}`;
}

/** True si la ruta existe en el mapa. */
export function routeExists(name: string): boolean {
    return ROUTE_MAP[name] !== undefined;
}
