import { apiFetch } from '@/lib/api';
import { putOutboxOp } from '@/lib/offline/db';
import { refreshPendingCount } from '@/lib/offline/sync-engine';
import { uuidv4 } from '@/lib/offline/uuid';
import { useSharedData } from '@/lib/shared-data';
import type { PaymentMethod } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface TableOrderItem {
    id: string;
    name: string;
    price: number;
    quantity: number;
    category: string;
    notes?: string;
}

export interface TableOrder {
    id: string;
    status: string;
    table_number: string;
    items: TableOrderItem[];
    item_count: number;
    subtotal?: number;
    tax_amount?: number;
    tax_rate?: number;
    tax_included_in_price?: boolean;
    tip_amount?: number;
    total: number;
    client_phone: string | null;
    ordered_at: string | null;
}

export type { PaymentMethod };

export interface ClosePaymentInput {
    payment_method: PaymentMethod;
    amount_received?: number;
    reference?: string;
    tip_amount?: number;
}

interface UseTablesReturn {
    tableOrders: TableOrder[];
    loading: boolean;
    error: string | null;
    lastUpdated: Date | undefined;
    refresh: () => Promise<void>;
    appendItems: (orderId: string, items: { id: string; quantity: number; notes?: string }[]) => Promise<void>;
    /** Resuelve `{ queued: true }` si el cobro se encoló offline (tiquete provisional). */
    closeWithPayment: (orderId: string, payload: ClosePaymentInput) => Promise<{ queued: boolean }>;
}

// 30s — intervalo canónico de refresh del frontend. Si el cajero necesita
// ver un cambio inmediato (cocina marcó listo) puede usar el botón
// "Refrescar" del header. 30s balancea costo de BD/red contra UX.
const POLL_INTERVAL_MS = 30_000;

export function useTables(token: string | null): UseTablesReturn {
    const { activeCompany, activeBranch } = useSharedData();
    const [tableOrders, setTableOrders] = useState<TableOrder[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [lastUpdated, setLastUpdated] = useState<Date | undefined>(undefined);
    const isMounted = useRef(true);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const fetchTables = useCallback(async (): Promise<void> => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/orders/tables');
            if (!isMounted.current) return;
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'Error al cargar mesas.');
                return;
            }
            const json = await res.json();
            setTableOrders((json as { data: TableOrder[] }).data ?? []);
            setLastUpdated(new Date());
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token]);

    const appendItems = useCallback(
        async (orderId: string, items: { id: string; quantity: number; notes?: string }[]): Promise<void> => {
            const res = await apiFetch(`/api/v1/orders/${orderId}/items`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items }),
            });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'Error al agregar ítems.');
            }
            await fetchTables();
        },
        [fetchTables],
    );

    useEffect(() => {
        void fetchTables();
        const interval = setInterval(() => void fetchTables(), POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, [fetchTables]);

    const closeWithPayment = useCallback(
        async (orderId: string, payload: ClosePaymentInput): Promise<{ queued: boolean }> => {
            let status: number | null = null;
            try {
                const res = await apiFetch(`/api/v1/orders/${orderId}/close-with-payment`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                status = res.status;
                if (res.ok) {
                    await fetchTables();
                    return { queued: false };
                }
                // 4xx = error real de negocio (orden ya cerrada, validación) → no encolar.
                if (status < 500) {
                    const json = await res.json().catch(() => ({}));
                    throw new Error((json as { message?: string }).message ?? 'Error al cerrar la mesa.');
                }
                // 5xx cae al encolado offline.
            } catch (e) {
                if (status !== null && status < 500) throw e;
                // fetch rechazó (sin red) → encolar.
            }

            // ── Cobro offline (plan §7.1, §9): encola order.close en el outbox.
            // El total lo fija el SERVIDOR al sync; aquí solo registramos la
            // intención de cobro con su client_uuid idempotente. Tiquete
            // provisional (no fiscal); la factura DIAN se emite al reconectar.
            const companyNit = activeCompany?.nit;
            const branchId = activeBranch?.id;
            if (!companyNit || !branchId) {
                throw new Error('Sin empresa/sede activa: no se puede cobrar offline.');
            }
            const nowIso = new Date().toISOString();
            await putOutboxOp({
                op_id: uuidv4(),
                type: 'order.close',
                company_nit: companyNit,
                branch_id: branchId,
                payload: {
                    client_uuid: uuidv4(),
                    order_id: orderId,
                    payment_method: payload.payment_method,
                    amount_received: payload.amount_received,
                    tip_amount: payload.tip_amount,
                    reference: payload.reference,
                    paid_at: nowIso,
                    occurred_at_client: nowIso,
                },
                created_at_client: nowIso,
                attempts: 0,
                status: 'queued',
                last_error: null,
                conflict: null,
            });
            await refreshPendingCount();
            return { queued: true };
        },
        [fetchTables, activeCompany?.nit, activeBranch?.id],
    );

    return { tableOrders, loading, error, lastUpdated, refresh: fetchTables, appendItems, closeWithPayment };
}
