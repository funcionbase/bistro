import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import type { PaymentMethod, PaymentReceiptMethod } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Polling de la sesión de caja activa de la empresa. La caja es transversal:
 * cualquier usuario que la abre o cierra cambia el estado para todos. El
 * polling cada 10s asegura que el resto del equipo vea el estado al instante.
 *
 * Modo offline (caja offline-first, plan-off.md §9): abrir/egreso/cerrar caja
 * funcionan sin red encolando ops en el outbox. La sesión se cachea para que la
 * caja siga visible tras recargar offline; la apertura offline crea una sesión
 * PROVISIONAL local (el server la concilia al sync). El cierre offline es
 * provisional: el server recalcula `expected_cash` con su verdad al reconectar.
 */

export interface CashSessionUser {
    id: string;
    name: string;
}

export interface CashSessionLiveSummary {
    by_method: Record<PaymentReceiptMethod, { gross: number; refunds: number; net: number; tips: number; count: number }>;
    expected_cash: number;
    orders_count: number;
    /** Órdenes en estados operativos del tablero. Si > 0 bloquea el cierre de caja. */
    pending_orders: number;
    expenses: {
        total: number;
        count: number;
        by_method: Record<PaymentMethod, number>;
        by_category: Record<string, number>;
    };
}

export type CashExpenseCategory = 'domiciliario_pago' | 'proveedor' | 'imprevisto' | 'propina_distribuida' | 'otro';

export const CASH_EXPENSE_CATEGORIES: Record<CashExpenseCategory, string> = {
    domiciliario_pago: 'Pago a domiciliario',
    proveedor: 'Proveedor',
    imprevisto: 'Imprevisto',
    propina_distribuida: 'Propina distribuida',
    otro: 'Otro',
};

export interface CashSession {
    id: string;
    status: 'open';
    opened_at: string | null;
    opening_amount: number;
    opened_by: CashSessionUser | null;
    opening_notes: string | null;
    live: CashSessionLiveSummary;
    /** true si fue abierta offline y aún no se sincronizó (sesión local). */
    provisional?: boolean;
}

export interface CashRegisterContext {
    menu_active: boolean;
    in_business_hours: boolean;
    /** true si caja cerrada Y menú activo Y dentro de horario hábil. */
    should_alert: boolean;
}

export interface CloseSessionResult {
    opening_amount: number;
    closing_amount: number;
    expected_cash: number;
    cash_difference: number;
    /** true si el cierre quedó encolado offline (provisional, se concilia al sync). */
    provisional?: boolean;
}

interface UseCashRegisterReturn {
    session: CashSession | null;
    context: CashRegisterContext | null;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    openSession: (openingAmount: number, notes?: string) => Promise<void>;
    closeSession: (closingAmount: number, notes?: string) => Promise<CloseSessionResult>;
    recordExpense: (input: { amount: number; category: CashExpenseCategory; description?: string; payment_method?: PaymentMethod }) => Promise<void>;
}

const POLL_INTERVAL_MS = 30_000;

/** Resumen `live` en cero para una sesión provisional offline (el server lo recalcula al sync). */
function emptyLiveSummary(): CashSessionLiveSummary {
    const zeroMethod = { gross: 0, refunds: 0, net: 0, tips: 0, count: 0 };
    return {
        by_method: { cash: { ...zeroMethod }, card: { ...zeroMethod }, transfer: { ...zeroMethod }, refund: { ...zeroMethod } },
        expected_cash: 0,
        orders_count: 0,
        pending_orders: 0,
        expenses: { total: 0, count: 0, by_method: { cash: 0, card: 0, transfer: 0 }, by_category: {} },
    };
}

/** Cuenta operaciones aún sin sincronizar (órdenes legacy + outbox). */
async function countAllPending(): Promise<number> {
    const { countPendingOrders, countPendingOutboxOps } = await import('@/lib/offline/db');
    const [orders, ops] = await Promise.all([countPendingOrders(), countPendingOutboxOps()]);
    return orders + ops;
}

export function useCashRegister(token: string | null): UseCashRegisterReturn {
    const { activeCompany, activeBranch, auth } = useSharedData();
    const companyNit = activeCompany?.nit ?? null;
    const branchId = activeBranch?.id ?? null;
    const [session, setSession] = useState<CashSession | null>(null);
    const [context, setContext] = useState<CashRegisterContext | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const isMounted = useRef(true);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const refresh = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/cash-register/current');
            if (!isMounted.current) return;
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'Error al consultar la caja.');
                return;
            }
            const json = await res.json();
            const body = (json as { data: { session: CashSession | null; context: CashRegisterContext } }).data;
            setSession(body?.session ?? null);
            setContext(body?.context ?? null);
            setError(null);
            // Snapshot para operar offline tras recargar (plan §7.3).
            if (companyNit) {
                const { putCachedCashSession } = await import('@/lib/offline/db');
                void putCachedCashSession(companyNit, { session: body?.session ?? null, context: body?.context ?? null });
            }
        } catch {
            if (!isMounted.current) return;
            // Sin red: caer al snapshot cacheado para que la caja siga visible.
            if (companyNit) {
                const { getCachedCashSession } = await import('@/lib/offline/db');
                const cached = await getCachedCashSession(companyNit);
                const payload = cached?.payload as { session: CashSession | null; context: CashRegisterContext | null } | undefined;
                if (payload) {
                    setSession(payload.session ?? null);
                    setContext(payload.context ?? null);
                }
            }
            setError('Sin conexión — mostrando el último estado conocido de la caja.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token, companyNit]);

    const openSession = useCallback(
        async (openingAmount: number, notes?: string) => {
            let status: number | null = null;
            try {
                const res = await apiFetch('/api/v1/cash-register/open', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ opening_amount: openingAmount, notes: notes ?? null }),
                });
                status = res.status;
                if (res.ok) {
                    await refresh();
                    return;
                }
                if (status < 500) {
                    const json = await res.json().catch(() => ({}));
                    throw new Error((json as { message?: string }).message ?? 'No se pudo abrir la caja.');
                }
            } catch (e) {
                if (status !== null && status < 500) throw e;
            }

            // Apertura offline (plan §9): encola cash.open + sesión provisional local.
            if (!companyNit || !branchId) throw new Error('Sin empresa/sede activa: no se puede abrir caja offline.');
            const { putOutboxOp, putCachedCashSession } = await import('@/lib/offline/db');
            const { uuidv4 } = await import('@/lib/offline/uuid');
            const { refreshPendingCount } = await import('@/lib/offline/sync-engine');
            const clientUuid = uuidv4();
            const nowIso = new Date().toISOString();
            await putOutboxOp({
                op_id: uuidv4(),
                type: 'cash.open',
                company_nit: companyNit,
                branch_id: branchId,
                payload: { client_uuid: clientUuid, opening_amount: openingAmount, notes: notes ?? null },
                created_at_client: nowIso,
                attempts: 0,
                status: 'queued',
                last_error: null,
                conflict: null,
            });
            const provisional: CashSession = {
                id: clientUuid,
                status: 'open',
                opened_at: nowIso,
                opening_amount: openingAmount,
                opened_by: auth?.user ? { id: auth.user.id, name: auth.user.name } : null,
                opening_notes: notes ?? null,
                live: emptyLiveSummary(),
                provisional: true,
            };
            await putCachedCashSession(companyNit, { session: provisional, context });
            setSession(provisional);
            await refreshPendingCount();
        },
        [refresh, companyNit, branchId, auth?.user, context],
    );

    const closeSession = useCallback(
        async (closingAmount: number, notes?: string): Promise<CloseSessionResult> => {
            // Online: exige 0 pendientes de sync (drenar primero, plan §6.4).
            if (navigator.onLine) {
                const pendingSyncCount = await countAllPending();
                if (pendingSyncCount > 0) {
                    throw new Error(
                        `Cierre bloqueado: hay ${pendingSyncCount} operación${pendingSyncCount === 1 ? '' : 'es'} pendiente${pendingSyncCount === 1 ? '' : 's'} de sincronizar. Espera al sync antes de cerrar.`,
                    );
                }
                let status: number | null = null;
                try {
                    const res = await apiFetch('/api/v1/cash-register/close', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ closing_amount: closingAmount, notes: notes ?? null, pending_sync_count: pendingSyncCount }),
                    });
                    status = res.status;
                    const json = await res.json().catch(() => ({}));
                    if (res.ok) {
                        await refresh();
                        return (json as { data: CloseSessionResult }).data;
                    }
                    // 4xx = error real de negocio → no encolar.
                    if (status < 500) throw new Error((json as { message?: string }).message ?? 'No se pudo cerrar la caja.');
                    // 5xx → cae al cierre offline.
                } catch (e) {
                    if (status !== null && status < 500) throw e;
                    // error de red → cae al cierre offline.
                }
            }

            // Cierre provisional offline (plan §6.4/§9): encola cash.close. El
            // server recalcula expected_cash y la diferencia al reconectar.
            if (!companyNit || !branchId) throw new Error('Sin empresa/sede activa: no se puede cerrar caja offline.');
            const { putOutboxOp, deleteCart } = await import('@/lib/offline/db');
            const { uuidv4 } = await import('@/lib/offline/uuid');
            const { refreshPendingCount } = await import('@/lib/offline/sync-engine');
            const nowIso = new Date().toISOString();
            await putOutboxOp({
                op_id: uuidv4(),
                type: 'cash.close',
                company_nit: companyNit,
                branch_id: branchId,
                payload: { client_uuid: uuidv4(), closing_amount: closingAmount, notes: notes ?? null, closed_at_client: nowIso },
                created_at_client: nowIso,
                attempts: 0,
                status: 'queued',
                last_error: null,
                conflict: null,
            });
            // Caja cerrada provisionalmente: limpiamos sesión local + carrito de la sede.
            setSession(null);
            if (branchId) void deleteCart(branchId);
            await refreshPendingCount();
            return { opening_amount: 0, closing_amount: closingAmount, expected_cash: 0, cash_difference: 0, provisional: true };
        },
        [refresh, companyNit, branchId],
    );

    const recordExpense = useCallback(
        async (input: { amount: number; category: CashExpenseCategory; description?: string; payment_method?: PaymentMethod }) => {
            let status: number | null = null;
            try {
                const res = await apiFetch('/api/v1/cash-register/expenses', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        amount: input.amount,
                        category: input.category,
                        description: input.description ?? null,
                        payment_method: input.payment_method ?? 'cash',
                    }),
                });
                status = res.status;
                if (res.ok) {
                    await refresh();
                    return;
                }
                if (status < 500) {
                    const json = await res.json().catch(() => ({}));
                    throw new Error((json as { message?: string }).message ?? 'No se pudo registrar el egreso.');
                }
            } catch (e) {
                if (status !== null && status < 500) throw e;
            }

            // Egreso offline (plan §9): encola cash.expense (server resuelve la sesión).
            if (!companyNit || !branchId) throw new Error('Sin empresa/sede activa: no se puede registrar egreso offline.');
            const { putOutboxOp } = await import('@/lib/offline/db');
            const { uuidv4 } = await import('@/lib/offline/uuid');
            const { refreshPendingCount } = await import('@/lib/offline/sync-engine');
            const nowIso = new Date().toISOString();
            await putOutboxOp({
                op_id: uuidv4(),
                type: 'cash.expense',
                company_nit: companyNit,
                branch_id: branchId,
                payload: {
                    client_uuid: uuidv4(),
                    amount: input.amount,
                    category: input.category,
                    description: input.description ?? null,
                    payment_method: input.payment_method ?? 'cash',
                    occurred_at_client: nowIso,
                },
                created_at_client: nowIso,
                attempts: 0,
                status: 'queued',
                last_error: null,
                conflict: null,
            });
            await refreshPendingCount();
        },
        [refresh, companyNit, branchId],
    );

    useEffect(() => {
        void refresh();
        const interval = setInterval(() => void refresh(), POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, [refresh]);

    return { session, context, loading, error, refresh, openSession, closeSession, recordExpense };
}
