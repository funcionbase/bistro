/**
 * Marker opaco que el frontend usa para representar "sesión autenticada".
 *
 * Estado FINAL deseado: el JWT vive únicamente en cookie HttpOnly (`flexyflow_jwt`)
 * inaccesible al JS; este marker reemplaza al token en la prop Inertia `token` para
 * que el código que verifica `if (token) ...` siga funcionando sin exponer el JWT.
 *
 * MIGRACIÓN TRANSITORIA: usuarios pre-cookie tienen el JWT real en `localStorage`.
 * Mientras la cookie HttpOnly no esté seteada, mantenemos el JWT en memoria y lo
 * enviamos como Bearer; el backend, en el primer request válido, devuelve la cookie
 * y el header `X-Cookie-Migrated: 1` que dispara `markCookieMigrated()` para borrar
 * `localStorage` y dejar de usar Bearer.
 */
export const AUTH_MARKER = '__authenticated__';

type TokenListener = (token: string) => void;

const listeners = new Set<TokenListener>();
let _legacyToken: string | null = null;
let _authenticated = false;

function looksLikeJwt(value: string): boolean {
    // JWTs reales tienen 3 segmentos base64url separados por puntos.
    return value.length > 50 && value.split('.').length === 3;
}

function stripTokenFromUrl(): void {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (!url.searchParams.has('token')) return;
    url.searchParams.delete('token');
    const search = url.searchParams.toString();
    const cleaned = url.pathname + (search ? `?${search}` : '') + url.hash;
    window.history.replaceState(null, '', cleaned);
}

/**
 * Devuelve, en orden:
 *   1. JWT legacy en memoria (si existe — para migración a cookie)
 *   2. Marker `__authenticated__` (si la sesión ya está confirmada)
 *   3. null
 *
 * `apiFetch` usa el resultado para decidir si manda Bearer o nada.
 */
export function getToken(): string | null {
    return _legacyToken ?? (_authenticated ? AUTH_MARKER : null);
}

/**
 * Registra una sesión activa. Recibe un marker ('present', '__authenticated__')
 * y activa el flag de autenticación. Ya NO acepta ni persiste JWTs — el token
 * vive solo en la cookie HttpOnly; el único JWT legacy admitido es el que
 * carga el bootstrap desde localStorage (migración transitoria, abajo).
 */
export function setToken(value: string | null | undefined): void {
    stripTokenFromUrl();
    if (!value) return;

    if (_authenticated) return;
    _authenticated = true;
    listeners.forEach((l) => l(AUTH_MARKER));
}

/**
 * Llamado cuando el backend confirma que ya seteó la cookie HttpOnly
 * (header `X-Cookie-Migrated: 1`). Borra el JWT legacy de memoria + localStorage
 * para que el JS deje de tener acceso al token.
 */
export function markCookieMigrated(): void {
    if (_legacyToken) {
        _legacyToken = null;
        try {
            localStorage.removeItem('token');
        } catch {
            // ignorar
        }
    }
}

export function clearToken(): void {
    _legacyToken = null;
    _authenticated = false;
    try {
        localStorage.removeItem('token');
    } catch {
        // ignorar
    }
}

export function subscribeToken(listener: TokenListener): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

// Bootstrap: cargar JWT legacy de localStorage si existe (migración transitoria).
if (typeof window !== 'undefined') {
    try {
        const stored = localStorage.getItem('token');
        if (stored && looksLikeJwt(stored)) {
            _legacyToken = stored;
            _authenticated = true;
        } else if (stored) {
            localStorage.removeItem('token');
        }
    } catch {
        // localStorage indisponible — ignorar
    }
}

// Limpia el `?token=` legacy de la URL al cargar (el OAuth callback ya
// setea la cookie HttpOnly; el query param es residual).
if (typeof window !== 'undefined') {
    stripTokenFromUrl();
}
