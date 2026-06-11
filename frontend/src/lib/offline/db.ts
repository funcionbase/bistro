/**
 * Capa IndexedDB para modo offline (#140).
 *
 * Object stores:
 *  - pending_orders: órdenes creadas offline (con `client_uuid` UUID v4) esperando sync.
 *  - pending_receipts: cobros offline.
 *  - cached_menu: snapshot del menú activo por empresa con TTL.
 *  - cached_cash_session: snapshot de la sesión de caja abierta por empresa.
 *  - sync_log: bitácora de eventos de sync (último sync OK, errores, etc.).
 *
 * Multitenant: cada record lleva `companyNit`. La cola de sync filtra por empresa
 * activa para evitar empujar pendientes a la empresa equivocada.
 */
import { openDB, type DBSchema, type IDBPDatabase } from 'idb';

const DB_NAME = 'flexyflow_offline';
const DB_VERSION = 1;

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

interface FlexyOfflineDB extends DBSchema {
    pending_orders: {
        key: string;
        value: PendingOrder;
        indexes: { 'by-company': string; 'by-created': string };
    };
    cached_menu: {
        key: string;
        value: CachedMenu;
    };
    cached_cash_session: {
        key: string;
        value: CachedCashSession;
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
            upgrade(db) {
                if (!db.objectStoreNames.contains('pending_orders')) {
                    const store = db.createObjectStore('pending_orders', { keyPath: 'client_uuid' });
                    store.createIndex('by-company', 'company_nit');
                    store.createIndex('by-created', 'created_at');
                }
                if (!db.objectStoreNames.contains('cached_menu')) {
                    db.createObjectStore('cached_menu', { keyPath: 'company_nit' });
                }
                if (!db.objectStoreNames.contains('cached_cash_session')) {
                    db.createObjectStore('cached_cash_session', { keyPath: 'company_nit' });
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
    await Promise.all([db.clear('pending_orders'), db.clear('cached_menu'), db.clear('cached_cash_session'), db.clear('sync_log')]);
}
