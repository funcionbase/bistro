import type { KanbanOrder } from '@/hooks/use-orders';
import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Lista TODAS las órdenes del día actual en TZ America/Bogota — alimenta la
 * vista "Ventas del día" (`/orders/deliveries`). Reemplaza al hook anterior
 * `useDeliveryList` que solo traía deliveries; ahora cubre todos los tipos
 * (table/delivery/pickup) y todos los estados (pending → refunded/cancelled).
 *
 * Backend: `/api/v1/reports/orders?period=daily&status=all&per_page=100`.
 * Permission: `reports.read`. Se usa el endpoint de reportes porque ya tiene
 * resumen agregado (gross/refunded/net) que la UI muestra como KPIs.
 */

export interface DaySalesSummary {
    total_orders: number;
    completed: number;
    failed?: number;
    cancelled: number;
    refunded?: number;
    abandoned: number;
    total_revenue: number;
    total_refunded?: number;
    net_revenue?: number;
}

export interface DaySalesOrder extends KanbanOrder {
    /** El reports endpoint puede traer campos extra; los toleramos. */
    cost?: number;
}

interface UseDaySalesReturn {
    orders: DaySalesOrder[];
    summary: DaySalesSummary | null;
    period: { from: string; to: string } | null;
    loading: boolean;
    error: string | null;
    statusFilter: string;
    setStatusFilter: (s: string) => void;
    refresh: () => Promise<void>;
    lastUpdated: Date | undefined;
}

const POLL_INTERVAL_MS = 30_000;

export function useDaySales(token: string | null): UseDaySalesReturn {
    const [orders, setOrders] = useState<DaySalesOrder[]>([]);
    const [summary, setSummary] = useState<DaySalesSummary | null>(null);
    const [period, setPeriod] = useState<{ from: string; to: string } | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [lastUpdated, setLastUpdated] = useState<Date | undefined>();
    const isMounted = useRef(true);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const fetchOrders = useCallback(async () => {
        if (!token) return;
        try {
            const params = new URLSearchParams({
                period: 'daily',
                status: statusFilter,
                per_page: '100',
                page: '1',
            });
            const res = await apiFetch(`/api/v1/reports/orders?${params.toString()}`);
            if (!isMounted.current) return;
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'No se pudo cargar las ventas del día.');
                return;
            }
            const json = await res.json();
            setOrders((json.orders ?? []) as DaySalesOrder[]);
            setSummary((json.summary as DaySalesSummary) ?? null);
            setPeriod((json.period as { from: string; to: string }) ?? null);
            setLastUpdated(new Date());
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token, statusFilter]);

    useEffect(() => {
        void fetchOrders();
        const interval = setInterval(() => void fetchOrders(), POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, [fetchOrders]);

    return {
        orders,
        summary,
        period,
        loading,
        error,
        statusFilter,
        setStatusFilter,
        refresh: fetchOrders,
        lastUpdated,
    };
}
