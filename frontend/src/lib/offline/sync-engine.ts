/**
 * Motor de sincronización para órdenes/cobros offline (#140).
 *
 * Garantías y estrategia:
 *  - Idempotencia por `client_uuid` (UUID v4). El backend usa lockForUpdate y
 *    devuelve el server_id existente si el batch se reintenta.
 *  - Multitenant: solo envía pendientes cuya `company_nit` coincide con la
 *    empresa activa; el resto queda en cola hasta que esa empresa sea la activa.
 *  - Backoff exponencial con jitter: 1s, 2s, 4s, 8s, 16s, 32s, max 60s.
 *  - Drenado agresivo cuando vuelve `online` para minimizar la ventana de
 *    exposición a evicciones del navegador.
 *  - Polling cada 30s mientras hay pendientes (cubre iOS Safari sin Background Sync).
 *
 * El frontend NO conoce el JWT; la cookie HttpOnly viaja por `credentials: include`.
 */
import { apiFetch } from '@/lib/api';
import { appendSyncLog, deletePendingOrder, getPendingOrders, type PendingOrder } from './db';

type SyncListener = (state: SyncState) => void;

export interface SyncState {
    online: boolean;
    syncing: boolean;
    pendingCount: number;
    lastSyncAt: string | null;
    lastErrorAt: string | null;
    lastError: string | null;
}

const listeners = new Set<SyncListener>();
let _state: SyncState = {
    online: typeof navigator !== 'undefined' ? navigator.onLine : true,
    syncing: false,
    pendingCount: 0,
    lastSyncAt: null,
    lastErrorAt: null,
    lastError: null,
};

let _attempt = 0;
let _retryTimer: ReturnType<typeof setTimeout> | null = null;
let _pollTimer: ReturnType<typeof setInterval> | null = null;
let _activeCompanyNit: string | null = null;

const BACKOFF_STEPS_MS = [1000, 2000, 4000, 8000, 16000, 32000, 60000];

function setState(patch: Partial<SyncState>): void {
    _state = { ..._state, ...patch };
    listeners.forEach((l) => l(_state));
}

export function getSyncState(): SyncState {
    return _state;
}

export function subscribeSyncState(l: SyncListener): () => void {
    listeners.add(l);
    l(_state);
    return () => listeners.delete(l);
}

export function setActiveCompanyForSync(nit: string | null): void {
    _activeCompanyNit = nit;
    void refreshPendingCount();
}

export async function refreshPendingCount(): Promise<number> {
    const list = await getPendingOrders(_activeCompanyNit ?? undefined);
    setState({ pendingCount: list.length });
    return list.length;
}

function nextDelay(): number {
    const step = BACKOFF_STEPS_MS[Math.min(_attempt, BACKOFF_STEPS_MS.length - 1)];
    const jitter = Math.random() * step * 0.25;
    return step + jitter;
}

function schedule(delayMs: number): void {
    if (_retryTimer) clearTimeout(_retryTimer);
    _retryTimer = setTimeout(() => {
        void runSync();
    }, delayMs);
}

/**
 * Drena la cola para la empresa activa enviando un único batch. Si el batch
 * tiene errores parciales, las órdenes con `status=created|duplicate|warning`
 * se borran de IndexedDB; las `failed` se quedan para reintento.
 */
export async function runSync(): Promise<void> {
    if (_state.syncing) return;
    if (!navigator.onLine) {
        setState({ online: false });
        return;
    }
    if (!_activeCompanyNit) return;

    const pending = await getPendingOrders(_activeCompanyNit);
    if (pending.length === 0) {
        setState({ pendingCount: 0 });
        return;
    }

    setState({ syncing: true });
    try {
        const response = await apiFetch('/api/v1/orders/sync-batch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ orders: pending }),
        });

        if (!response.ok) {
            const text = await response.text().catch(() => '');
            throw new Error(`Sync HTTP ${response.status}: ${text.slice(0, 200)}`);
        }

        const data = (await response.json()) as { results: Array<{ client_uuid: string; status: string; error?: string }> };
        let okCount = 0;
        let failCount = 0;
        for (const result of data.results ?? []) {
            if (result.status === 'created' || result.status === 'duplicate' || result.status === 'warning') {
                await deletePendingOrder(result.client_uuid);
                okCount++;
            } else {
                failCount++;
                // Reflejar error en el record para diagnóstico y bumpear attempts.
                const order = pending.find((o) => o.client_uuid === result.client_uuid);
                if (order) {
                    const updated: PendingOrder = {
                        ...order,
                        attempts: (order.attempts ?? 0) + 1,
                        last_attempt_at: new Date().toISOString(),
                        last_error: result.error ?? 'unknown',
                    };
                    const { putPendingOrder } = await import('./db');
                    await putPendingOrder(updated);
                }
            }
        }

        await appendSyncLog({
            company_nit: _activeCompanyNit,
            event: failCount === 0 ? 'sync_ok' : 'sync_error',
            detail: `synced=${okCount} failed=${failCount}`,
            occurred_at: new Date().toISOString(),
        });

        setState({
            syncing: false,
            lastSyncAt: new Date().toISOString(),
            lastError: failCount > 0 ? `Algunas operaciones fallaron (${failCount}). Reintentando...` : null,
            lastErrorAt: failCount > 0 ? new Date().toISOString() : _state.lastErrorAt,
        });

        if (failCount === 0) {
            _attempt = 0;
        } else {
            _attempt++;
            schedule(nextDelay());
        }

        await refreshPendingCount();
    } catch (e) {
        const msg = e instanceof Error ? e.message : 'sync failed';
        setState({
            syncing: false,
            lastError: msg,
            lastErrorAt: new Date().toISOString(),
        });
        await appendSyncLog({
            company_nit: _activeCompanyNit,
            event: 'sync_error',
            detail: msg.slice(0, 200),
            occurred_at: new Date().toISOString(),
        });
        _attempt++;
        schedule(nextDelay());
    }
}

export function startSyncEngine(): () => void {
    if (typeof window === 'undefined') return () => {};

    const onOnline = () => {
        setState({ online: true });
        _attempt = 0;
        void runSync();
    };
    const onOffline = () => setState({ online: false });

    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);

    if (!_pollTimer) {
        _pollTimer = setInterval(() => {
            if (navigator.onLine && _state.pendingCount > 0 && !_state.syncing) {
                void runSync();
            }
        }, 30000);
    }

    void refreshPendingCount();
    if (navigator.onLine) {
        // arranque suave: 1s tras montar para no bloquear el primer paint
        schedule(1000);
    }

    return () => {
        window.removeEventListener('online', onOnline);
        window.removeEventListener('offline', onOffline);
        if (_pollTimer) {
            clearInterval(_pollTimer);
            _pollTimer = null;
        }
        if (_retryTimer) {
            clearTimeout(_retryTimer);
            _retryTimer = null;
        }
    };
}

/**
 * Exporta la cola actual (para empresa activa) como JSON descargable. Sirve
 * como respaldo manual si el usuario teme perder pendientes.
 */
export async function exportPendingsAsJson(): Promise<string> {
    const pending = await getPendingOrders(_activeCompanyNit ?? undefined);
    return JSON.stringify(
        {
            exported_at: new Date().toISOString(),
            company_nit: _activeCompanyNit,
            count: pending.length,
            orders: pending,
        },
        null,
        2,
    );
}
