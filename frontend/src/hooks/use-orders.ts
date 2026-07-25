import { apiFetch } from '@/lib/api';
import type { PaymentMethod } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface KanbanOrderItem {
    id: string;
    name: string;
    price: number;
    quantity: number;
    category: string;
    notes?: string;
}

/**
 * Nota de la orden (scope group | kitchen_alert). Viene en el detalle de
 * orden (`GET /api/v1/orders/{id}`) — no en el listado del kanban.
 * `author_role`: 'guest' (comensal QR) | 'waiter' (User mesero).
 */
export interface KanbanOrderNote {
    id: string;
    scope: 'group' | 'kitchen_alert' | string;
    body: string;
    author_label: string | null;
    author_role: 'guest' | 'waiter' | null;
    created_at: string | null;
}

/**
 * Resumen de una orden hermana en la misma sesión de mesa. Aparece en el
 * detalle de orden cuando esa mesa generó múltiples órdenes (cliente pidió
 * varias tandas o cajero sumó pedidos a la cuenta).
 */
export interface KanbanRelatedOrder {
    id: string;
    status: string;
    total: number;
    ordered_at: string | null;
    table_number: string | null;
}

/**
 * Item canónico (filas `order_items` table). Para órdenes de mesa con QR
 * los items viven acá, no en el JSON legacy `orders.items`. El detalle de
 * orden devuelve ambos; el modal prefiere `line_items` cuando existe.
 */
export interface KanbanOrderLineItem {
    id: string | null;
    menu_item_id: string | null;
    name: string;
    quantity: number;
    unit_price: number;
    notes: string | null;
    status: string | null;
    cancellation_reason: string | null;
    guest_label: string | null;
}

export interface KanbanOrderDelivery {
    id: string;
    status: string;
    deliverer: { id: string; name: string } | null;
}

export interface KanbanOrderPayment {
    method: PaymentMethod | null;
    reference: string | null;
    amount_received?: number | null;
    change_returned?: number | null;
    paid_at?: string | null;
}

export interface KanbanOrderRefund {
    original_method: PaymentMethod | null;
    total_refunded: number | null;
    reference: string | null;
    refunded_at?: string | null;
    /** Suma acumulada de TODAS las devoluciones de la orden (refunds parciales). */
    total_refunded_all?: number;
    /** Monto aún reembolsable (order.total - total_refunded_all). */
    remaining_refundable?: number;
    is_partial?: boolean;
}

export interface KanbanOrder {
    id: string;
    status: string;
    order_type: 'table' | 'delivery' | 'pickup' | null;
    /* status uses canonical OrderStatus from @/types — declared as string for compat with poll responses */
    table_number: string | null;
    delivery_address: string | null;
    client_phone: string | null;
    contact_id?: string | null;
    client_name?: string | null;
    items: KanbanOrderItem[];
    subtotal?: number;
    tax_amount?: number;
    tax_rate?: number;
    tax_regime?: string | null;
    tax_included_in_price?: boolean;
    tip_amount?: number;
    total: number;
    discount_amount: number;
    coupon_code: string | null;
    ordered_at: string | null;
    /** Última modificación. En kanban se usa para ordenar (recientes arriba). */
    updated_at?: string | null;
    chat_id: string | null;
    delivery: KanbanOrderDelivery | null;
    payment?: KanbanOrderPayment | null;
    refund?: KanbanOrderRefund | null;
    /** Notas grupales / alertas de cocina. Solo viene en el detalle. */
    notes?: KanbanOrderNote[];
    /** Items canónicos (filas `order_items`). Para mesa con QR `items` JSON queda null. */
    line_items?: KanbanOrderLineItem[];
    /** Sesión grupal de mesa a la que pertenece (null si la orden no es de mesa). */
    table_session_id?: string | null;
    /** Otras órdenes de la misma sesión — solo viene en el detalle. */
    related_orders?: KanbanRelatedOrder[];
}

interface UseOrdersReturn {
    orders: KanbanOrder[];
    loading: boolean;
    error: string | null;
    lastUpdated: Date | undefined;
    refresh: () => Promise<void>;
    updateStatus: (orderId: string, status: string) => Promise<string[]>;
}

const POLL_INTERVAL_MS = 30_000;

export function useOrders(token: string | null): UseOrdersReturn {
    const [orders, setOrders] = useState<KanbanOrder[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [lastUpdated, setLastUpdated] = useState<Date | undefined>(undefined);
    const isMounted = useRef(true);
    // Época de mutación: cada update optimista la incrementa. Un fetch de
    // polling captura la época al arrancar y, si cambió mientras estaba en
    // vuelo, no aplica su resultado — así un tick viejo no pisa el estado
    // optimista con el status anterior del server (F13). Auto-converge: el
    // siguiente tick sin mutación intermedia sí aplica la verdad del server.
    const mutationEpoch = useRef(0);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const fetchOrders = useCallback(async (): Promise<void> => {
        if (!token) return;
        const epoch = mutationEpoch.current;
        try {
            const res = await apiFetch('/api/v1/orders');
            if (!isMounted.current) return;
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'Error al cargar órdenes.');
                return;
            }
            const json = await res.json();
            // Si una mutación optimista ocurrió mientras este fetch estaba en
            // vuelo, su payload trae el status anterior del server → no lo
            // aplicamos para no provocar el rebote visual de la tarjeta.
            if (epoch !== mutationEpoch.current) return;
            setOrders((json as { data: KanbanOrder[] }).data ?? []);
            setLastUpdated(new Date());
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token]);

    const updateStatus = useCallback(
        async (orderId: string, status: string): Promise<string[]> => {
            // Actualización optimista. Bump de época para que cualquier poll en
            // vuelo descarte su payload (con el status viejo) al volver.
            mutationEpoch.current += 1;
            setOrders((prev) => prev.map((o) => (o.id === orderId ? { ...o, status } : o)));

            let res: Response;
            try {
                res = await apiFetch(`/api/v1/orders/${orderId}/status`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ status }),
                });
            } catch (err) {
                // apiFetch lanzó (red caída): revertir el optimista con la verdad
                // del server y re-lanzar para que el caller muestre el error.
                await fetchOrders();
                throw err;
            }

            if (!res.ok) {
                // Revertir en error
                await fetchOrders();
                const json = await res.json().catch(() => ({}));
                throw new Error((json as { message?: string }).message ?? 'Error al actualizar estado.');
            }

            const json = await res.json().catch(() => ({})) as { data?: { inventory_warnings?: string[] } };
            return json.data?.inventory_warnings ?? [];
        },
        [fetchOrders],
    );

    useEffect(() => {
        void fetchOrders();
        const interval = setInterval(() => {
            if (document.hidden) return; // pestaña oculta: no gastar backend
            void fetchOrders();
        }, POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, [fetchOrders]);

    // Refresca al volver el foco / hacerse visible la pestaña, para que el
    // tablero y el KDS (/kds) reflejen los cambios del otro al alternar entre
    // pestañas sin esperar al tick de polling.
    useEffect(() => {
        const refetchOnReturn = () => {
            if (document.visibilityState === 'visible') void fetchOrders();
        };
        window.addEventListener('focus', refetchOnReturn);
        document.addEventListener('visibilitychange', refetchOnReturn);
        return () => {
            window.removeEventListener('focus', refetchOnReturn);
            document.removeEventListener('visibilitychange', refetchOnReturn);
        };
    }, [fetchOrders]);

    return { orders, loading, error, lastUpdated, refresh: fetchOrders, updateStatus };
}
