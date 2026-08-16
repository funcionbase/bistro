import { AUTH_MARKER, clearToken, getToken, markCookieMigrated } from './token';

/**
 * Base del backend. En dev queda vacío → paths relativos que resuelve el
 * proxy de Vite. En producción cross-origin (#220) es `VITE_API_URL`
 * (ej. https://bistro-api.example.com) — el frontend vive en otro
 * host (bistro.example.com), así que los paths relativos no sirven.
 */
const API_BASE_URL = (typeof import.meta !== 'undefined' && (import.meta as { env?: Record<string, string> }).env?.VITE_API_URL) ?? '';

/** Antepone el host del backend a paths relativos; deja intactas las URLs absolutas. */
export function resolveBackendUrl(url: string): string {
    if (url.startsWith('http')) {
        return url;
    }
    return `${API_BASE_URL}${url.startsWith('/') ? '' : '/'}${url}`;
}

/**
 * Wrapper sobre `fetch` que envía credenciales (cookies) y maneja 401.
 *
 * Estado FINAL: el JWT vive como cookie HttpOnly `bistro_jwt` que el browser
 * adjunta automáticamente vía `credentials: 'include'`. El frontend NO envía
 * Bearer ni accede al JWT — robo por XSS imposible.
 *
 * MIGRACIÓN: si el frontend tiene un JWT legacy en memoria (de localStorage
 * pre-cookie), lo envía como Bearer una sola vez. El middleware backend detecta
 * la ausencia de cookie, la setea, y devuelve `X-Cookie-Migrated: 1`; en ese
 * momento llamamos `markCookieMigrated()` para borrar el legacy y dejar de
 * enviar Bearer.
 */
export async function apiFetch(url: string, options: RequestInit = {}): Promise<Response> {
    const headers = new Headers(options.headers as HeadersInit | undefined);

    if (!headers.has('Accept') && !headers.has('accept')) {
        headers.set('Accept', 'application/json');
    }

    // Si el frontend tiene JWT legacy (transición), enviarlo como Bearer para que
    // el backend pueda autenticar y migrar a cookie. Si solo es el marker, no
    // mandar Authorization (la cookie HttpOnly ya viaja sola).
    const token = getToken();
    if (token && token !== AUTH_MARKER) {
        headers.set('Authorization', `Bearer ${token}`);
    } else if (headers.has('Authorization')) {
        headers.delete('Authorization');
    }

    const response = await fetch(resolveBackendUrl(url), {
        ...options,
        headers,
        credentials: options.credentials ?? 'include',
    });

    // Backend confirmó que la cookie HttpOnly ya está seteada — dejar de usar Bearer.
    if (response.headers.get('X-Cookie-Migrated') === '1') {
        markCookieMigrated();
    }

    // #154: si el gate `EnsureCompanyVerified` rechaza, redirigir a la pantalla
    // "Cuenta en revisión". El backend marca este caso con `code=company_not_verified`
    // para distinguirlo de otros 403 (RBAC, sede no autorizada, etc.).
    //
    // Nota #193: 403 `company_payment_blocked` NO se auto-redirige a /billing.
    // El middleware web (`EnsureCompanyNotBlocked`) ya redirige las rutas
    // web bloqueadas, y el dashboard híbrido necesita seguir mostrando KPIs
    // arriba del SuspendedBanner. Redirigir cada fetch fallido rompería
    // esa UX. El componente que recibe el 403 puede mostrar fallback o
    // ignorarlo — el banner global cubre la llamada a acción.
    if (response.status === 403) {
        const cloned = response.clone();
        try {
            const data = await cloned.json();
            if (data?.code === 'company_not_verified' && typeof window !== 'undefined' && window.location.pathname !== '/company/under-review') {
                window.location.assign('/company/under-review');
                return response;
            }
        } catch {
            // respuesta no-JSON, dejar que el llamador la maneje
        }
    }

    if (response.status === 401) {
        const cloned = response.clone();
        try {
            const data = await cloned.json();
            const msg = (data?.message ?? '').toString().toLowerCase();
            // Cualquier 401 de auth (revocada, token ausente, token inválido o expirado)
            // significa que la sesión local se perdió → limpiar y volver al login.
            // Detección primaria por `code` (ValidateJwt) con fallback a substrings
            // para respuestas que aún no lo incluyen.
            const isAuthFailure =
                data?.code === 'auth_failed' ||
                msg.includes('revoc') ||
                msg.includes('token no proporcionado') ||
                msg.includes('token inválido') ||
                msg.includes('token invalido') ||
                msg.includes('expirado');

            if (isAuthFailure) {
                clearToken();
                // Rutas auth-aware manejan el 401 por su cuenta (ej. /verify-email
                // usa el 401 para entrar en modo sin-sesión post-registro);
                // redirigir acá las rebotaría al landing.
                const authAwarePaths = ['/verify-email'];
                if (
                    typeof window !== 'undefined' &&
                    window.location.pathname !== '/' &&
                    !authAwarePaths.includes(window.location.pathname)
                ) {
                    window.location.assign('/');
                }
                return response;
            }
        } catch {
            // respuesta no-JSON, dejar que el llamador la maneje
        }
    }

    return response;
}
