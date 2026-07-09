/**
 * Cliente API DIAN (HU #235). Wrapper fino sobre `apiFetch` con:
 *  - Idempotency-Key automático para el endpoint de emisión.
 *  - Helpers para descarga vía URL firmada S3 (xml/pdf).
 */
import { apiFetch } from '@/lib/api';
import type {
    DianDefaultRecipient,
    DianDocumentType,
    DianElectronicDocument,
    DianFiscalProfile,
    DianFiscalProfileResponse,
    DianProviderConfig,
    DianRecipientLookup,
    DianResolution,
} from '@/types/dian';

interface PaginatedResponse<T> {
    data: T[];
    meta?: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

function jsonHeaders(extra?: Record<string, string>): Record<string, string> {
    return { 'Content-Type': 'application/json', Accept: 'application/json', ...(extra ?? {}) };
}

function randomKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return Date.now().toString(36) + Math.random().toString(36).slice(2);
}

/**
 * Error de la API DIAN con el código de error del backend preservado.
 *
 * El backend responde `{ error|code: '<slug>', message: '<texto>' }` en los
 * fallos 4xx. Antes propagábamos el cuerpo JSON crudo como mensaje (el usuario
 * veía `{"error":"dian.resolution_unavailable",...}` literal). Ahora extraemos
 * `message` para mostrar y `code` para que la UI lo mapee a un texto amigable.
 */
export class DianApiError extends Error {
    constructor(
        message: string,
        public readonly code: string | null,
        public readonly status: number,
        /** Errores 422 por campo (`{ campo: [mensajes] }`), para render inline. */
        public readonly errors: Record<string, string[]> | null = null,
    ) {
        super(message);
        this.name = 'DianApiError';
    }
}

async function handle<T>(res: Response): Promise<T> {
    if (!res.ok) {
        const raw = await res.text();
        let code: string | null = null;
        let message = '';
        let errors: Record<string, string[]> | null = null;
        try {
            const parsed = JSON.parse(raw) as { error?: string; code?: string; message?: string; errors?: Record<string, string[]> };
            code = parsed.error ?? parsed.code ?? null;
            message = parsed.message ?? '';
            errors = parsed.errors ?? null;
        } catch {
            // Respuesta no-JSON (HTML de error, texto plano): usar el cuerpo tal cual.
            message = raw;
        }
        throw new DianApiError(message || `HTTP ${res.status}`, code, res.status, errors);
    }
    return res.json() as Promise<T>;
}

// --- Perfil fiscal ---
export async function getFiscalProfile(): Promise<DianFiscalProfileResponse> {
    return handle(await apiFetch('/api/v1/dian/fiscal-profile'));
}
export async function updateFiscalProfile(payload: Partial<DianFiscalProfile>): Promise<DianFiscalProfileResponse> {
    return handle(
        await apiFetch('/api/v1/dian/fiscal-profile', {
            method: 'PUT',
            headers: jsonHeaders(),
            body: JSON.stringify(payload),
        }),
    );
}

// --- Resoluciones ---
export async function listResolutions(): Promise<{ data: DianResolution[] }> {
    return handle(await apiFetch('/api/v1/dian/resolutions'));
}
export async function createResolution(payload: Partial<DianResolution> & { technical_key: string }): Promise<{ data: DianResolution }> {
    return handle(
        await apiFetch('/api/v1/dian/resolutions', {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify(payload),
        }),
    );
}
export async function updateResolution(id: string, payload: Partial<DianResolution>): Promise<{ data: DianResolution }> {
    return handle(
        await apiFetch(`/api/v1/dian/resolutions/${id}`, {
            method: 'PUT',
            headers: jsonHeaders(),
            body: JSON.stringify(payload),
        }),
    );
}
export async function deactivateResolution(id: string): Promise<void> {
    await apiFetch(`/api/v1/dian/resolutions/${id}`, { method: 'DELETE' });
}

// --- Provider config ---
export async function getProviderConfig(): Promise<{ data: DianProviderConfig | null }> {
    return handle(await apiFetch('/api/v1/dian/provider-config'));
}
export async function updateProviderConfig(payload: {
    provider_slug: string;
    api_base_url?: string | null;
    api_token?: string | null;
    software_id?: string | null;
    software_pin?: string | null;
    test_set_id?: string | null;
    environment: 'habilitacion' | 'produccion';
    webhook_secret?: string | null;
}): Promise<{ data: DianProviderConfig }> {
    return handle(
        await apiFetch('/api/v1/dian/provider-config', {
            method: 'PUT',
            headers: jsonHeaders(),
            body: JSON.stringify(payload),
        }),
    );
}

// --- Default recipient ---
export async function getDefaultRecipient(): Promise<{ data: DianDefaultRecipient | null }> {
    return handle(await apiFetch('/api/v1/dian/default-recipient'));
}
export async function updateDefaultRecipient(payload: Partial<DianDefaultRecipient>): Promise<{ data: DianDefaultRecipient }> {
    return handle(
        await apiFetch('/api/v1/dian/default-recipient', {
            method: 'PUT',
            headers: jsonHeaders(),
            body: JSON.stringify(payload),
        }),
    );
}
export async function deleteDefaultRecipient(): Promise<void> {
    await apiFetch('/api/v1/dian/default-recipient', { method: 'DELETE' });
}

// --- Recipients lookup ---
export async function lookupRecipient(phone: string): Promise<DianRecipientLookup> {
    return handle(await apiFetch(`/api/v1/dian/recipients/lookup?phone=${encodeURIComponent(phone)}`));
}
export async function updateContactDianProfile(contactId: string, payload: Record<string, unknown>): Promise<{ data: { id: string; dian_complete: boolean } }> {
    return handle(
        await apiFetch(`/api/v1/dian/recipients/${contactId}/dian-profile`, {
            method: 'PUT',
            headers: jsonHeaders(),
            body: JSON.stringify(payload),
        }),
    );
}

// --- Documentos ---
export async function listDocuments(params: {
    status?: string;
    document_type?: string;
    from?: string;
    to?: string;
    /** 'all' = toda la empresa · uuid = esa sede · ausente = sede activa. */
    branch?: string;
    order_id?: string;
    /** Filtra por la resolución DIAN a la que quedó ligado el documento. */
    resolution_id?: string;
    /** Búsqueda server-side: número completo, CUFE/CUDE o track ID. */
    q?: string;
    /** Columna de ordenamiento (whitelist backend; default issued_at). */
    sort?: string;
    dir?: 'asc' | 'desc';
    per_page?: number;
    page?: number;
} = {}): Promise<PaginatedResponse<DianElectronicDocument>> {
    const search = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => v != null && v !== '' && search.set(k, String(v)));
    const q = search.toString();
    return handle(await apiFetch(`/api/v1/dian/documents${q ? `?${q}` : ''}`));
}
export async function getDocument(id: string): Promise<{ data: DianElectronicDocument }> {
    return handle(await apiFetch(`/api/v1/dian/documents/${id}`));
}
export async function emitDocument(payload: {
    order_id: string;
    document_type: DianDocumentType;
    references_document_id?: string;
    force_print?: boolean;
    printer_id?: string;
}): Promise<{ data: DianElectronicDocument }> {
    return handle(
        await apiFetch('/api/v1/dian/documents', {
            method: 'POST',
            headers: jsonHeaders({ 'Idempotency-Key': randomKey() }),
            body: JSON.stringify(payload),
        }),
    );
}
export async function retryDocument(id: string): Promise<{ data: DianElectronicDocument }> {
    return handle(await apiFetch(`/api/v1/dian/documents/${id}/retry`, { method: 'POST' }));
}
export async function emitCreditNote(id: string): Promise<{ data: DianElectronicDocument }> {
    return handle(await apiFetch(`/api/v1/dian/documents/${id}/credit-note`, { method: 'POST' }));
}
export async function convertToFev(id: string): Promise<{ data: DianElectronicDocument }> {
    return handle(await apiFetch(`/api/v1/dian/documents/${id}/convert-to-fev`, { method: 'POST' }));
}
export async function printDocument(id: string, printerId?: string): Promise<void> {
    await apiFetch(`/api/v1/dian/documents/${id}/print`, {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ printer_id: printerId, force: true }),
    });
}
export async function getDocumentXmlUrl(id: string): Promise<{ url: string; ttl_seconds: number }> {
    return handle(await apiFetch(`/api/v1/dian/documents/${id}/xml`));
}
export async function getDocumentPdfUrl(id: string): Promise<{ url: string; ttl_seconds: number }> {
    return handle(await apiFetch(`/api/v1/dian/documents/${id}/pdf`));
}
