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
import {
    appendSyncLog,
    countPendingOutboxOps,
    deleteOutboxOp,
    deletePendingOrder,
    getConflictOutboxOps,
    getOutboxOp,
    getPendingOrders,
    getQueuedOutboxOps,
    putIdMap,
    putOutboxOp,
    type OutboxOp,
    type PendingOrder,
} from './db';

type SyncListener = (state: SyncState) => void;

export interface SyncState {
    online: boolean;
    syncing: boolean;
    pendingCount: number;
    /** Ops marcadas en conflicto por el server — requieren atención del usuario. */
    conflictCount: number;
    lastSyncAt: string | null;
    lastErrorAt: string | null;
    lastError: string | null;
}

const listeners = new Set<SyncListener>();
let _state: SyncState = {
    online: typeof navigator !== 'undefined' ? navigator.onLine : true,
    syncing: false,
    pendingCount: 0,
    conflictCount: 0,
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
    // Pendientes = órdenes legacy (`pending_orders`) + ops del outbox unificado
    // aún no aplicadas. Ambas cuentan para el banner y el bloqueo de cierre.
    const [list, outboxCount, conflicts] = await Promise.all([
        getPendingOrders(_activeCompanyNit ?? undefined),
        countPendingOutboxOps(_activeCompanyNit ?? undefined),
        getConflictOutboxOps(_activeCompanyNit ?? undefined),
    ]);
    const total = list.length + outboxCount;
    setState({ pendingCount: total, conflictCount: conflicts.length });
    // Si hay pendientes, pedir un wake en background (best-effort).
    if (total > 0) {
        void registerBackgroundSync();
    }
    return total;
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
 * Drena ambas colas de la empresa activa: las órdenes legacy (`pending_orders`
 * → `/orders/sync-batch`) y el outbox unificado (`/sync/batch`). Si algo falla
 * por red se reintenta con backoff; los conflictos NO se reintentan auto (el
 * usuario debe resolverlos en la pantalla de revisión, plan §8).
 */
export async function runSync(): Promise<void> {
    if (_state.syncing) return;
    if (!navigator.onLine) {
        setState({ online: false });
        return;
    }
    if (!_activeCompanyNit) return;

    const [legacyPending, outboxOps] = await Promise.all([getPendingOrders(_activeCompanyNit), getQueuedOutboxOps(_activeCompanyNit)]);
    if (legacyPending.length === 0 && outboxOps.length === 0) {
        await refreshPendingCount();
        return;
    }

    setState({ syncing: true });
    let okCount = 0;
    let failCount = 0;
    let conflictCount = 0;
    let hadNetworkError = false;

    try {
        if (legacyPending.length > 0) {
            const r = await drainLegacyOrders(legacyPending);
            okCount += r.ok;
            failCount += r.fail;
        }
        if (outboxOps.length > 0) {
            const r = await drainOutbox(outboxOps);
            okCount += r.ok;
            failCount += r.fail;
            conflictCount += r.conflicts;
        }

        await appendSyncLog({
            company_nit: _activeCompanyNit,
            event: failCount === 0 ? 'sync_ok' : 'sync_error',
            detail: `synced=${okCount} failed=${failCount} conflicts=${conflictCount}`,
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
        hadNetworkError = true;
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
        await refreshPendingCount();
    }

    if (hadNetworkError) {
        _attempt++;
        schedule(nextDelay());
    }
}

/**
 * Drena las órdenes legacy vía `/orders/sync-batch`. Lanza ante error de red
 * (se propaga al backoff del orquestador).
 */
async function drainLegacyOrders(pending: PendingOrder[]): Promise<{ ok: number; fail: number }> {
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
    let ok = 0;
    let fail = 0;
    for (const result of data.results ?? []) {
        if (result.status === 'created' || result.status === 'duplicate' || result.status === 'warning') {
            await deletePendingOrder(result.client_uuid);
            ok++;
        } else {
            fail++;
            const order = pending.find((o) => o.client_uuid === result.client_uuid);
            if (order) {
                const { putPendingOrder } = await import('./db');
                await putPendingOrder({
                    ...order,
                    attempts: (order.attempts ?? 0) + 1,
                    last_attempt_at: new Date().toISOString(),
                    last_error: result.error ?? 'unknown',
                });
            }
        }
    }
    return { ok, fail };
}

interface SyncBatchResult {
    op_id: string;
    status: 'created' | 'warning' | 'duplicate' | 'conflict' | 'failed';
    server_id?: string;
    code?: string;
    error?: string;
    warnings?: unknown[];
}

/**
 * Drena el outbox vía `/sync/batch`. Concilia por-op:
 *  - applied/warning/duplicate → guarda id_map y borra la op.
 *  - conflict → marca la op (no se reintenta auto; pantalla de revisión).
 *  - failed → re-encola con backoff.
 * Lanza ante error de red (se propaga al backoff del orquestador).
 */
async function drainOutbox(ops: OutboxOp[]): Promise<{ ok: number; fail: number; conflicts: number }> {
    const body = {
        ops: ops.map((op) => ({
            op_id: op.op_id,
            type: op.type,
            company_nit: op.company_nit,
            branch_id: op.branch_id,
            payload: op.payload,
            entity_ref: op.entity_ref,
            depends_on: op.depends_on,
            created_at_client: op.created_at_client,
        })),
    };
    const response = await apiFetch('/api/v1/sync/batch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    if (!response.ok) {
        const text = await response.text().catch(() => '');
        throw new Error(`Sync outbox HTTP ${response.status}: ${text.slice(0, 200)}`);
    }
    const data = (await response.json()) as { results: SyncBatchResult[] };
    let ok = 0;
    let fail = 0;
    let conflicts = 0;
    for (const result of data.results ?? []) {
        const op = ops.find((o) => o.op_id === result.op_id);
        if (!op) continue;
        if (result.status === 'created' || result.status === 'warning' || result.status === 'duplicate') {
            if (op.entity_ref && result.server_id) {
                await putIdMap(op.entity_ref, result.server_id, op.type);
            }
            await deleteOutboxOp(op.op_id);
            ok++;
        } else if (result.status === 'conflict') {
            conflicts++;
            await putOutboxOp({
                ...op,
                status: 'conflict',
                conflict: { code: result.code ?? 'conflict', message: result.error, warnings: result.warnings },
                last_error: result.code ?? result.error ?? 'conflict',
                last_attempt_at: new Date().toISOString(),
            });
        } else {
            fail++;
            await putOutboxOp({
                ...op,
                status: 'queued',
                attempts: (op.attempts ?? 0) + 1,
                last_attempt_at: new Date().toISOString(),
                last_error: result.error ?? 'unknown',
            });
        }
    }
    return { ok, fail, conflicts };
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

    // El SW (Background Sync) nos despierta para drenar con la pestaña en foco.
    const onSwMessage = (event: MessageEvent) => {
        if ((event.data as { type?: string } | null)?.type === 'pwa:flush-outbox' && navigator.onLine) {
            void runSync();
        }
    };
    navigator.serviceWorker?.addEventListener('message', onSwMessage);

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
        navigator.serviceWorker?.removeEventListener('message', onSwMessage);
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

// ─────────────────────────────────────────────────────────────────────────
// Gestión de conflictos (pantalla de revisión, plan §7.5/§8)
// ─────────────────────────────────────────────────────────────────────────

/** Ops marcadas en conflicto por el server para la empresa activa. */
export async function listConflicts(): Promise<OutboxOp[]> {
    return getConflictOutboxOps(_activeCompanyNit ?? undefined);
}

/**
 * Reintenta un conflicto: vuelve la op a `queued` y dispara el sync. Útil
 * cuando la causa se resolvió (p.ej. se reabrió la caja en server).
 */
export async function retryConflict(opId: string): Promise<void> {
    const op = await getOutboxOp(opId);
    if (!op) return;
    await putOutboxOp({ ...op, status: 'queued', conflict: null, last_error: null });
    await refreshPendingCount();
    void runSync();
}

/**
 * Descarta un conflicto: borra la op del outbox. Se usa cuando el conflicto es
 * definitivo (p.ej. la orden ya fue cobrada en server: no hay nada que hacer).
 * NO borra plata: el asiento contable ya vive (o no) en el server.
 */
export async function discardConflict(opId: string): Promise<void> {
    await deleteOutboxOp(opId);
    await refreshPendingCount();
}

// ─────────────────────────────────────────────────────────────────────────
// Background Sync API (plan §14, best-effort)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Registra un `sync` tag para que el navegador drene el outbox aun con la
 * pestaña cerrada (soporte parcial: Chromium). El SW despierta a los clientes
 * abiertos vía postMessage; el engine en-foreground es el fallback real.
 */
async function registerBackgroundSync(): Promise<void> {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;
    try {
        const reg = (await navigator.serviceWorker.ready) as ServiceWorkerRegistration & {
            sync?: { register: (tag: string) => Promise<void> };
        };
        await reg.sync?.register('flush-outbox');
    } catch {
        // Background Sync no soportado / sin permiso → fallback foreground.
    }
}
