import { apiFetch } from './api';

/**
 * Cliente HTTP tipado para la SPA (#220).
 *
 * Construido encima de `apiFetch` para reutilizar:
 *  - Migración de Bearer legacy → cookie HttpOnly.
 *  - Redirect a `/company/under-review` en 403 `company_not_verified`.
 *  - Logout en 401 expirado/revocado.
 *
 * Cuando la migración SPA cierra (Fase 5), este módulo se vuelve el ÚNICO
 * cliente HTTP de la app. Hoy convive con `apiFetch` directo + `router`
 * de Inertia sin pisarse.
 */

const API_BASE_URL = (typeof import.meta !== 'undefined' && (import.meta as { env?: Record<string, string> }).env?.VITE_API_URL) ?? '';

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly body: unknown,
        public readonly response: Response,
    ) {
        const msg = (body as { message?: string } | null)?.message ?? response.statusText ?? `HTTP ${status}`;
        super(msg);
        this.name = 'ApiError';
    }

    /** El backend retornó un body JSON con un campo `code` (convención Laravel). */
    get code(): string | null {
        return (this.body as { code?: string } | null)?.code ?? null;
    }

    /** Errores de validación 422 (FormRequest). */
    get errors(): Record<string, string[]> | null {
        return (this.body as { errors?: Record<string, string[]> } | null)?.errors ?? null;
    }
}

interface RequestOptions {
    signal?: AbortSignal;
    headers?: Record<string, string>;
    /** Query params serializados al URL. Strings y números; arrays repiten el key. */
    params?: Record<string, string | number | boolean | Array<string | number> | undefined | null>;
}

function buildUrl(path: string, params?: RequestOptions['params']): string {
    const base = path.startsWith('http') ? path : `${API_BASE_URL}${path.startsWith('/') ? '' : '/'}${path}`;
    if (!params) {
        return base;
    }
    const url = new URL(base, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
    for (const [key, value] of Object.entries(params)) {
        if (value === undefined || value === null) {
            continue;
        }
        if (Array.isArray(value)) {
            for (const v of value) {
                url.searchParams.append(key, String(v));
            }
        } else {
            url.searchParams.set(key, String(value));
        }
    }
    // Preservar relative cuando el base era relative (no se pierde el origin externo).
    return path.startsWith('http') ? url.toString() : `${url.pathname}${url.search}`;
}

async function request<T>(method: string, path: string, body?: unknown, options: RequestOptions = {}): Promise<T> {
    const init: RequestInit = {
        method,
        headers: {
            'Content-Type': 'application/json',
            ...(options.headers ?? {}),
        },
        signal: options.signal,
    };

    if (body !== undefined && method !== 'GET' && method !== 'HEAD') {
        init.body = JSON.stringify(body);
    }

    const url = buildUrl(path, options.params);
    const response = await apiFetch(url, init);

    if (response.status === 204) {
        return undefined as T;
    }

    const contentType = response.headers.get('content-type') ?? '';
    const isJson = contentType.includes('application/json');
    const data = isJson ? await response.json() : await response.text();

    if (!response.ok) {
        throw new ApiError(response.status, data, response);
    }

    return data as T;
}

export const apiClient = {
    get: <T>(path: string, options?: RequestOptions) => request<T>('GET', path, undefined, options),
    post: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>('POST', path, body, options),
    put: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>('PUT', path, body, options),
    patch: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>('PATCH', path, body, options),
    delete: <T>(path: string, options?: RequestOptions) => request<T>('DELETE', path, undefined, options),
} as const;

export type ApiClient = typeof apiClient;
