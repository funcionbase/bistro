/**
 * Capa IndexedDB para modo offline (#140 + caja offline-first).
 *
 * Object stores (v2):
 *  - pending_orders: órdenes creadas offline (legacy #140) — flujo de creación de
 *    orden vía `/orders/sync-batch`. Se conserva mientras coexiste con el outbox.
 *  - outbox: cola unificada de mutaciones tipadas (cobro, caja, append, refund…)
 *    con orden causal e idempotency-key por operación. Drena a `/sync/batch`.
 *  - cart: carrito en curso por sede, para sobrevivir recargas offline.
 *  - id_map: mapea `client_uuid` → `server_id` tras el sync (resolver dependencias).
 *  - cached_menu: snapshot del menú activo por empresa con TTL.
 *  - cached_cash_session: snapshot de la sesión de caja abierta por empresa.
 *  - cached_bootstrap: snapshot de permisos/roles/catálogos para operar offline (RBAC).
 *  - sync_log: bitácora de eventos de sync (último sync OK, errores, etc.).
 *
 * Multitenant: cada record lleva `companyNit`. La cola de sync filtra por empresa
 * activa para evitar empujar pendientes a la empresa equivocada.
 */
import { openDB, type DBSchema, type IDBPDatabase } from 'idb';

const DB_NAME = 'flexyflow_offline';
const DB_VERSION = 2;

import type { PaymentMethod as CanonicalPaymentMethod } from '@/types';

export type OrderType = 'table' | 'delivery' | 'pickup';
export type PaymentMethod = CanonicalPaymentMethod;

export interface PendingOrderItem {
    id: string;
    quantity: number;
    notes?: string;
}

export interface PendingPayment {
    client_uuid: string;
    method: PaymentMethod;
    amount_received?: number;
    tip_amount?: number;
    reference?: string;
    paid_at: string;
}

export interface PendingOrder {
    client_uuid: string;
    company_nit: string;
    order_type: OrderType;
    client_phone?: string | null;
    table_number?: string | null;
    delivery_address?: string | null;
    items: PendingOrderItem[];
    created_at: string;
    payment?: PendingPayment;
    last_attempt_at?: string | null;
    attempts: number;
    last_error?: string | null;
}

export interface CachedMenu {
    company_nit: string;
    payload: unknown;
    cached_at: string;
}

export interface CachedCashSession {
    company_nit: string;
    payload: unknown;
    cached_at: string;
}

export interface SyncLogEntry {
    id?: number;
    company_nit: string;
    event: 'sync_ok' | 'sync_error' | 'order_queued' | 'order_synced' | 'cleared';
    detail?: string;
    occurred_at: string;
}

/**
 * Tipo de operación encolada en el outbox. Cada uno mapea a un endpoint/handler
 * real del backend; el sync los despacha a `/sync/batch` que reusa la lógica
 * online (sin duplicar reglas contables).
 */
export type OutboxOpType =
    | 'cash.open'
    | 'order.create'
    | 'order.append'
    | 'order.status'
    | 'order.close'
    | 'order.refund'
    | 'cash.expense'
    | 'cash.income'
    | 'cash.close';

export type OutboxOpStatus = 'queued' | 'in_flight' | 'applied' | 'conflict' | 'failed';

/**
 * Una mutación pendiente. `op_id` es la idempotency-key estable (UUID v4
 * generado UNA vez en el cliente). `depends_on` mantiene el orden causal
 * (p.ej. un cobro depende de la creación de su orden). `entity_ref` apunta al
 * `client_uuid` de la entidad principal para resolver `server_id` vía `id_map`.
 */
export interface OutboxOp {
    op_id: string;
    type: OutboxOpType;
    company_nit: string;
    branch_id: string;
    payload: Record<string, unknown>;
    depends_on?: string[];
    entity_ref?: string;
    created_at_client: string;
    attempts: number;
    last_error?: string | null;
    last_attempt_at?: string | null;
    status: OutboxOpStatus;
    /** Detalle del conflicto reportado por el server (para la pantalla de revisión). */
    conflict?: { code: string; message?: string; warnings?: unknown[] } | null;
}

/** Carrito en curso por sede (persistido para sobrevivir recargas offline). */
export interface CartSnapshot {
    branch_id: string;
    company_nit: string;
    payload: unknown;
    updated_at: string;
}

/** Mapeo `client_uuid` → `server_id` (resolver dependencias tras el sync). */
export interface IdMapEntry {
    client_uuid: string;
    server_id: string;
    type: OutboxOpType | string;
    mapped_at: string;
}

/** Snapshot de permisos/roles/catálogos para operar offline (RBAC best-effort). */
export interface CachedBootstrap {
    company_nit: string;
    payload: unknown;
    cached_at: string;
}

interface FlexyOfflineDB extends DBSchema {
    pending_orders: {
        key: string;
        value: PendingOrder;
        indexes: { 'by-company': string; 'by-created': string };
    };
    outbox: {
        key: string;
        value: OutboxOp;
        indexes: { 'by-company': string; 'by-status': string; 'by-created': string };
    };
    cart: {
        key: string;
        value: CartSnapshot;
    };
    id_map: {
        key: string;
        value: IdMapEntry;
    };
    cached_menu: {
        key: string;
        value: CachedMenu;
    };
    cached_cash_session: {
        key: string;
        value: CachedCashSession;
    };
    cached_bootstrap: {
        key: string;
        value: CachedBootstrap;
    };
    sync_log: {
        key: number;
        value: SyncLogEntry;
        indexes: { 'by-occurred': string };
    };
}

let _dbPromise: Promise<IDBPDatabase<FlexyOfflineDB>> | null = null;

export function getOfflineDb(): Promise<IDBPDatabase<FlexyOfflineDB>> {
    if (!_dbPromise) {
        _dbPromise = openDB<FlexyOfflineDB>(DB_NAME, DB_VERSION, {
            // Migración v1→v2 ADITIVA: nunca borramos `pending_orders` ni datos
            // existentes (un update de SW no puede perder ventas, plan §14/§17).
            // El outbox coexiste con `pending_orders`: el flujo legacy de creación
            // de orden sigue drenando por `/orders/sync-batch` hasta que se migre
            // por completo al outbox, evitando doble proceso del mismo registro.
            upgrade(db) {
                if (!db.objectStoreNames.contains('pending_orders')) {
                    const store = db.createObjectStore('pending_orders', { keyPath: 'client_uuid' });
                    store.createIndex('by-company', 'company_nit');
                    store.createIndex('by-created', 'created_at');
                }
                if (!db.objectStoreNames.contains('outbox')) {
                    const store = db.createObjectStore('outbox', { keyPath: 'op_id' });
                    store.createIndex('by-company', 'company_nit');
                    store.createIndex('by-status', 'status');
                    store.createIndex('by-created', 'created_at_client');
                }
                if (!db.objectStoreNames.contains('cart')) {
                    db.createObjectStore('cart', { keyPath: 'branch_id' });
                }
                if (!db.objectStoreNames.contains('id_map')) {
                    db.createObjectStore('id_map', { keyPath: 'client_uuid' });
                }
                if (!db.objectStoreNames.contains('cached_menu')) {
                    db.createObjectStore('cached_menu', { keyPath: 'company_nit' });
                }
                if (!db.objectStoreNames.contains('cached_cash_session')) {
                    db.createObjectStore('cached_cash_session', { keyPath: 'company_nit' });
                }
                if (!db.objectStoreNames.contains('cached_bootstrap')) {
                    db.createObjectStore('cached_bootstrap', { keyPath: 'company_nit' });
                }
                if (!db.objectStoreNames.contains('sync_log')) {
                    const store = db.createObjectStore('sync_log', { keyPath: 'id', autoIncrement: true });
                    store.createIndex('by-occurred', 'occurred_at');
                }
            },
        });
    }
    return _dbPromise;
}

export async function putPendingOrder(order: PendingOrder): Promise<void> {
    const db = await getOfflineDb();
    await db.put('pending_orders', order);
}

export async function deletePendingOrder(clientUuid: string): Promise<void> {
    const db = await getOfflineDb();
    await db.delete('pending_orders', clientUuid);
}

export async function getPendingOrders(companyNit?: string): Promise<PendingOrder[]> {
    const db = await getOfflineDb();
    if (!companyNit) {
        return db.getAll('pending_orders');
    }
    return db.getAllFromIndex('pending_orders', 'by-company', companyNit);
}

export async function countPendingOrders(companyNit?: string): Promise<number> {
    const orders = await getPendingOrders(companyNit);
    return orders.length;
}

// ─────────────────────────────────────────────────────────────────────────
// Outbox (cola unificada de mutaciones tipadas)
// ─────────────────────────────────────────────────────────────────────────

export async function putOutboxOp(op: OutboxOp): Promise<void> {
    const db = await getOfflineDb();
    await db.put('outbox', op);
}

export async function getOutboxOp(opId: string): Promise<OutboxOp | undefined> {
    const db = await getOfflineDb();
    return db.get('outbox', opId);
}

export async function deleteOutboxOp(opId: string): Promise<void> {
    const db = await getOfflineDb();
    await db.delete('outbox', opId);
}

/** Todas las ops de una empresa (o globales), ordenadas por `created_at_client`. */
export async function getOutboxOps(companyNit?: string): Promise<OutboxOp[]> {
    const db = await getOfflineDb();
    const all = companyNit ? await db.getAllFromIndex('outbox', 'by-company', companyNit) : await db.getAll('outbox');
    return all.sort((a, b) => a.created_at_client.localeCompare(b.created_at_client));
}

/** Ops drenables (estado `queued`) para la empresa activa, en orden causal. */
export async function getQueuedOutboxOps(companyNit: string): Promise<OutboxOp[]> {
    const ops = await getOutboxOps(companyNit);
    return ops.filter((op) => op.status === 'queued' || op.status === 'in_flight');
}

/** Cuenta ops que aún no llegaron a server (queued/in_flight) — para bloqueos y UI. */
export async function countPendingOutboxOps(companyNit?: string): Promise<number> {
    const ops = await getOutboxOps(companyNit);
    return ops.filter((op) => op.status === 'queued' || op.status === 'in_flight').length;
}

/** Ops marcadas en conflicto, para la pantalla de revisión. */
export async function getConflictOutboxOps(companyNit?: string): Promise<OutboxOp[]> {
    const ops = await getOutboxOps(companyNit);
    return ops.filter((op) => op.status === 'conflict');
}

// ─────────────────────────────────────────────────────────────────────────
// id_map (client_uuid → server_id)
// ─────────────────────────────────────────────────────────────────────────

export async function putIdMap(clientUuid: string, serverId: string, type: OutboxOpType | string): Promise<void> {
    const db = await getOfflineDb();
    await db.put('id_map', { client_uuid: clientUuid, server_id: serverId, type, mapped_at: new Date().toISOString() });
}

export async function getServerId(clientUuid: string): Promise<string | undefined> {
    const db = await getOfflineDb();
    const entry = await db.get('id_map', clientUuid);
    return entry?.server_id;
}

// ─────────────────────────────────────────────────────────────────────────
// Carrito en curso (por sede)
// ─────────────────────────────────────────────────────────────────────────

export async function putCart(branchId: string, companyNit: string, payload: unknown): Promise<void> {
    const db = await getOfflineDb();
    await db.put('cart', { branch_id: branchId, company_nit: companyNit, payload, updated_at: new Date().toISOString() });
}

export async function getCart(branchId: string): Promise<CartSnapshot | undefined> {
    const db = await getOfflineDb();
    return db.get('cart', branchId);
}

export async function deleteCart(branchId: string): Promise<void> {
    const db = await getOfflineDb();
    await db.delete('cart', branchId);
}

// ─────────────────────────────────────────────────────────────────────────
// Snapshot de bootstrap (permisos/roles/catálogos para RBAC offline)
// ─────────────────────────────────────────────────────────────────────────

export async function putCachedBootstrap(companyNit: string, payload: unknown): Promise<void> {
    const db = await getOfflineDb();
    await db.put('cached_bootstrap', { company_nit: companyNit, payload, cached_at: new Date().toISOString() });
}

export async function getCachedBootstrap(companyNit: string): Promise<CachedBootstrap | undefined> {
    const db = await getOfflineDb();
    return db.get('cached_bootstrap', companyNit);
}

export async function putCachedMenu(companyNit: string, payload: unknown): Promise<void> {
    const db = await getOfflineDb();
    await db.put('cached_menu', { company_nit: companyNit, payload, cached_at: new Date().toISOString() });
}

export async function getCachedMenu(companyNit: string): Promise<CachedMenu | undefined> {
    const db = await getOfflineDb();
    return db.get('cached_menu', companyNit);
}

export async function putCachedCashSession(companyNit: string, payload: unknown): Promise<void> {
    const db = await getOfflineDb();
    await db.put('cached_cash_session', { company_nit: companyNit, payload, cached_at: new Date().toISOString() });
}

export async function getCachedCashSession(companyNit: string): Promise<CachedCashSession | undefined> {
    const db = await getOfflineDb();
    return db.get('cached_cash_session', companyNit);
}

export async function appendSyncLog(entry: Omit<SyncLogEntry, 'id'>): Promise<void> {
    const db = await getOfflineDb();
    await db.add('sync_log', entry);
    // Trim: mantener solo las últimas 200 entradas para no engordar.
    const all = await db.getAllFromIndex('sync_log', 'by-occurred');
    if (all.length > 200) {
        const toDrop = all.slice(0, all.length - 200);
        const tx = db.transaction('sync_log', 'readwrite');
        for (const e of toDrop) {
            if (e.id !== undefined) {
                await tx.store.delete(e.id);
            }
        }
        await tx.done;
    }
}

export async function getRecentSyncLog(limit = 20): Promise<SyncLogEntry[]> {
    const db = await getOfflineDb();
    const all = await db.getAllFromIndex('sync_log', 'by-occurred');
    return all.slice(-limit).reverse();
}

/**
 * Pide al navegador almacenamiento persistente. Chrome lo concede automático si
 * la PWA está instalada; Firefox pregunta al usuario; Safari lo ignora pero no
 * falla. Sin esto, IndexedDB es "best-effort" y elegible para evicción LRU.
 */
export async function requestPersistentStorage(): Promise<boolean> {
    if (typeof navigator === 'undefined' || !navigator.storage?.persist) {
        return false;
    }
    try {
        return await navigator.storage.persist();
    } catch {
        return false;
    }
}

/**
 * @returns ratio `usage/quota` en [0, 1] o null si la API no está disponible.
 */
export async function estimateStorageUsage(): Promise<number | null> {
    if (typeof navigator === 'undefined' || !navigator.storage?.estimate) {
        return null;
    }
    try {
        const { usage, quota } = await navigator.storage.estimate();
        if (!usage || !quota) return null;
        return usage / quota;
    } catch {
        return null;
    }
}

export async function clearAllOfflineData(): Promise<void> {
    const db = await getOfflineDb();
    await Promise.all([
        db.clear('pending_orders'),
        db.clear('outbox'),
        db.clear('cart'),
        db.clear('id_map'),
        db.clear('cached_menu'),
        db.clear('cached_cash_session'),
        db.clear('cached_bootstrap'),
        db.clear('sync_log'),
    ]);
}
