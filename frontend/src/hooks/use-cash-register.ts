import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import type { PaymentMethod, PaymentReceiptMethod } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Polling de cajas de la sede activa. Cada caja puede tener una sesión abierta
 * (turno). En sedes mono-caja el comportamiento es idéntico al original; en
 * multi-caja el usuario elige qué caja opera (persistido en localStorage).
 *
 * Modo offline (plan-off.md §9): abrir/egreso/cerrar funcionan sin red
 * encolando ops en el outbox. La sesión se cachea para que la caja siga visible
 * tras recargar offline.
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
    cash_register_id: string | null;
    cash_register_name: string | null;
    live: CashSessionLiveSummary;
    /** true si fue abierta offline y aún no se sincronizó (sesión local). */
    provisional?: boolean;
}

export interface CashRegister {
    id: string;
    name: string;
    is_active: boolean;
    sort_order: number;
    archived: boolean;
    open_session: {
        id: string;
        opened_at: string | null;
        opening_amount: number;
        opened_by: CashSessionUser | null;
    } | null;
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

const POLL_INTERVAL_MS = 30_000;
const SELECTED_REGISTER_KEY = 'flexyflow.selected_register_id';

function selectedRegisterKey(branchId: string): string {
    return `${SELECTED_REGISTER_KEY}:${branchId}`;
}

interface UseCashRegisterReturn {
    session: CashSession | null;
    context: CashRegisterContext | null;
    /** true cuando NO hay ninguna caja abierta Y el local debería estar operando. */
    shouldAlert: boolean;
    loading: boolean;
    error: string | null;
    /** Catálogo de cajas de la sede activa. */
    registers: CashRegister[];
    /** ID de la caja que el usuario eligió operar en este dispositivo. */
    selectedRegisterId: string | null;
    /** Caja seleccionada (desde el catálogo). */
    selectedRegister: CashRegister | null;
    selectRegister: (id: string | null) => void;
    refresh: () => Promise<void>;
    openSession: (openingAmount: number, notes?: string, cashRegisterId?: string) => Promise<void>;
    closeSession: (closingAmount: number, notes?: string) => Promise<CloseSessionResult>;
    recordExpense: (input: { amount: number; category: CashExpenseCategory; description?: string; payment_method?: PaymentMethod }) => Promise<void>;
}

/** Resumen `live` en cero para una sesión provisional offline. */
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

    const [registers, setRegisters] = useState<CashRegister[]>([]);
    const [selectedRegisterId, setSelectedRegisterId] = useState<string | null>(() => {
        if (!branchId) return null;
        return localStorage.getItem(selectedRegisterKey(branchId));
    });
    const [session, setSession] = useState<CashSession | null>(null);
    const [context, setContext] = useState<CashRegisterContext | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const isMounted = useRef(true);
    // Refs allow refresh to always read fresh values without being recreated on
    // every state change (which would restart the polling interval).
    const registersRef = useRef<CashRegister[]>([]);
    const selectedRegisterIdRef = useRef<string | null>(null);
    registersRef.current = registers;
    selectedRegisterIdRef.current = selectedRegisterId;

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    // Cuando cambia la sede, resetear la selección a la guardada en localStorage.
    useEffect(() => {
        if (!branchId) return;
        const saved = localStorage.getItem(selectedRegisterKey(branchId));
        setSelectedRegisterId(saved);
    }, [branchId]);

    const selectRegister = useCallback(
        (id: string | null) => {
            if (!branchId) return;
            if (id) {
                localStorage.setItem(selectedRegisterKey(branchId), id);
                setSelectedRegisterId(id);
            } else {
                localStorage.removeItem(selectedRegisterKey(branchId));
                setSelectedRegisterId(null);
            }
        },
        [branchId],
    );

    const refresh = useCallback(async () => {
        if (!token) return;
        try {
            // 1. Catálogo de cajas de la sede (estado + sesiones abiertas).
            const regsRes = await apiFetch('/api/v1/cash-register/registers');
            if (!isMounted.current) return;

            let updatedRegisters = registersRef.current;
            if (regsRes.ok) {
                const regsJson = await regsRes.json();
                updatedRegisters = ((regsJson as { data: CashRegister[] }).data ?? []).filter((r) => !r.archived);
                setRegisters(updatedRegisters);

                // Auto-selección: si hay exactamente 1 caja activa → elegirla.
                if (updatedRegisters.length === 1 && !selectedRegisterIdRef.current) {
                    const onlyId = updatedRegisters[0].id;
                    if (branchId) localStorage.setItem(selectedRegisterKey(branchId), onlyId);
                    setSelectedRegisterId(onlyId);
                }
            }

            // 2. Sesión activa: para la caja elegida ó fallback mono-caja.
            const selected = updatedRegisters.find((r) => r.id === selectedRegisterIdRef.current);
            const openSessionId = selected?.open_session?.id ?? null;
            const currentUrl = openSessionId
                ? `/api/v1/cash-register/current?cash_session_id=${openSessionId}`
                : '/api/v1/cash-register/current';

            const curRes = await apiFetch(currentUrl);
            if (!isMounted.current) return;
            if (!curRes.ok) {
                const json = await curRes.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'Error al consultar la caja.');
                return;
            }
            const curJson = await curRes.json();
            const body = (curJson as { data: { session: CashSession | null; context: CashRegisterContext } }).data;
            // Multi-caja: si elegimos una caja libre el backend devuelve la
            // sesión de OTRA caja (activeSessionForBranch). La descartamos para
            // que OpenSessionScreen aparezca con el monto inicial.
            const effectiveSession = selectedRegisterIdRef.current && !openSessionId ? null : (body?.session ?? null);
            setSession(effectiveSession);
            setContext(body?.context ?? null);
            setError(null);

            // Snapshot para operar offline tras recargar (plan §7.3).
            if (companyNit) {
                const { putCachedCashSession } = await import('@/lib/offline/db');
                void putCachedCashSession(companyNit, { session: body?.session ?? null, context: body?.context ?? null });
            }
        } catch {
            if (!isMounted.current) return;
            // Sin red: caer al snapshot cacheado.
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
    }, [token, companyNit, branchId]);

    const openSession = useCallback(
        async (openingAmount: number, notes?: string, cashRegisterId?: string) => {
            let status: number | null = null;
            try {
                const res = await apiFetch('/api/v1/cash-register/open', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        opening_amount: openingAmount,
                        notes: notes ?? null,
                        cash_register_id: cashRegisterId ?? null,
                    }),
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
                payload: {
                    client_uuid: clientUuid,
                    opening_amount: openingAmount,
                    notes: notes ?? null,
                    cash_register_id: cashRegisterId ?? null,
                },
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
                cash_register_id: cashRegisterId ?? null,
                cash_register_name: registers.find((r) => r.id === cashRegisterId)?.name ?? null,
                live: emptyLiveSummary(),
                provisional: true,
            };
            await putCachedCashSession(companyNit, { session: provisional, context });
            setSession(provisional);
            await refreshPendingCount();
        },
        [refresh, companyNit, branchId, auth?.user, context, registers],
    );

    const closeSession = useCallback(
        async (closingAmount: number, notes?: string): Promise<CloseSessionResult> => {
            // Online: exige 0 pendientes de sync.
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
                        body: JSON.stringify({
                            closing_amount: closingAmount,
                            notes: notes ?? null,
                            pending_sync_count: pendingSyncCount,
                            cash_session_id: session?.id ?? null,
                        }),
                    });
                    status = res.status;
                    const json = await res.json().catch(() => ({}));
                    if (res.ok) {
                        await refresh();
                        return (json as { data: CloseSessionResult }).data;
                    }
                    if (status < 500) throw new Error((json as { message?: string }).message ?? 'No se pudo cerrar la caja.');
                } catch (e) {
                    if (status !== null && status < 500) throw e;
                }
            }

            // Cierre provisional offline.
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
                payload: {
                    client_uuid: uuidv4(),
                    closing_amount: closingAmount,
                    notes: notes ?? null,
                    closed_at_client: nowIso,
                    cash_session_id: session?.id ?? null,
                },
                created_at_client: nowIso,
                attempts: 0,
                status: 'queued',
                last_error: null,
                conflict: null,
            });
            setSession(null);
            if (branchId) void deleteCart(branchId);
            await refreshPendingCount();
            return { opening_amount: 0, closing_amount: closingAmount, expected_cash: 0, cash_difference: 0, provisional: true };
        },
        [refresh, companyNit, branchId, session?.id],
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
                        cash_session_id: session?.id ?? null,
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

            // Egreso offline.
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
                    cash_session_id: session?.id ?? null,
                },
                created_at_client: nowIso,
                attempts: 0,
                status: 'queued',
                last_error: null,
                conflict: null,
            });
            await refreshPendingCount();
        },
        [refresh, companyNit, branchId, session?.id],
    );

    useEffect(() => {
        void refresh();
        const interval = setInterval(() => void refresh(), POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, [refresh]);

    // shouldAlert: ninguna caja abierta en la sede + debería estar operando.
    const noOpenRegisters = registers.length > 0
        ? registers.filter((r) => r.is_active && !r.archived).every((r) => !r.open_session)
        : !session;
    const shouldAlert = noOpenRegisters && (context?.menu_active ?? false) && (context?.in_business_hours ?? false);

    const selectedRegister = registers.find((r) => r.id === selectedRegisterId) ?? null;

    return {
        session,
        context,
        shouldAlert,
        loading,
        error,
        registers,
        selectedRegisterId,
        selectedRegister,
        selectRegister,
        refresh,
        openSession,
        closeSession,
        recordExpense,
    };
}
