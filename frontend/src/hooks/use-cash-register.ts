import { apiFetch } from '@/lib/api';
import type { PaymentMethod, PaymentReceiptMethod } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Polling de la sesión de caja activa de la empresa. La caja es transversal:
 * cualquier usuario que la abre o cierra cambia el estado para todos. El
 * polling cada 10s asegura que el resto del equipo vea el estado al instante.
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
}

export interface CashRegisterContext {
    menu_active: boolean;
    in_business_hours: boolean;
    /** true si caja cerrada Y menú activo Y dentro de horario hábil. */
    should_alert: boolean;
}

interface UseCashRegisterReturn {
    session: CashSession | null;
    context: CashRegisterContext | null;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    openSession: (openingAmount: number, notes?: string) => Promise<void>;
    closeSession: (
        closingAmount: number,
        notes?: string,
    ) => Promise<{
        opening_amount: number;
        closing_amount: number;
        expected_cash: number;
        cash_difference: number;
    }>;
    recordExpense: (input: { amount: number; category: CashExpenseCategory; description?: string; payment_method?: PaymentMethod }) => Promise<void>;
}

const POLL_INTERVAL_MS = 30_000;

export function useCashRegister(token: string | null): UseCashRegisterReturn {
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
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token]);

    const openSession = useCallback(
        async (openingAmount: number, notes?: string) => {
            const res = await apiFetch('/api/v1/cash-register/open', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ opening_amount: openingAmount, notes: notes ?? null }),
            });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'No se pudo abrir la caja.');
            }
            await refresh();
        },
        [refresh],
    );

    const closeSession = useCallback(
        async (closingAmount: number, notes?: string) => {
            // Modo offline (#140): si hay órdenes en IndexedDB sin sincronizar,
            // bloqueamos el cierre client-side antes de pegarle al backend (que
            // también lo bloquea). Esto evita un round-trip y da un mensaje
            // más descriptivo en la UI.
            const { countPendingOrders } = await import('@/lib/offline/db');
            const pendingSyncCount = await countPendingOrders();
            if (pendingSyncCount > 0) {
                throw new Error(
                    `Cierre bloqueado: hay ${pendingSyncCount} operación${pendingSyncCount === 1 ? '' : 'es'} pendiente${pendingSyncCount === 1 ? '' : 's'} de sincronizar. Espera al sync antes de cerrar.`,
                );
            }
            const res = await apiFetch('/api/v1/cash-register/close', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ closing_amount: closingAmount, notes: notes ?? null, pending_sync_count: pendingSyncCount }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error((json as { message?: string }).message ?? 'No se pudo cerrar la caja.');
            }
            await refresh();
            return (json as { data: { opening_amount: number; closing_amount: number; expected_cash: number; cash_difference: number } }).data;
        },
        [refresh],
    );

    const recordExpense = useCallback(
        async (input: { amount: number; category: CashExpenseCategory; description?: string; payment_method?: PaymentMethod }) => {
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
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'No se pudo registrar el egreso.');
            }
            await refresh();
        },
        [refresh],
    );

    useEffect(() => {
        void refresh();
        const interval = setInterval(() => void refresh(), POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, [refresh]);

    return { session, context, loading, error, refresh, openSession, closeSession, recordExpense };
}
