import type { KanbanOrder } from '@/hooks/use-orders';
import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';

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
    cost?: number;
}

export interface DaySalesParams {
    dateFrom: string;
    dateTo: string;
    search: string;
    minAmount: string;
    maxAmount: string;
    status: string;
}

interface UseDaySalesReturn {
    orders: DaySalesOrder[];
    summary: DaySalesSummary | null;
    period: { from: string; to: string } | null;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    lastUpdated: Date | undefined;
}

const POLL_INTERVAL_MS = 30_000;

export function useDaySales(token: string | null, params: DaySalesParams): UseDaySalesReturn {
    const [orders, setOrders] = useState<DaySalesOrder[]>([]);
    const [summary, setSummary] = useState<DaySalesSummary | null>(null);
    const [period, setPeriod] = useState<{ from: string; to: string } | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
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
            const urlParams = new URLSearchParams({
                period: 'custom',
                date_from: params.dateFrom,
                date_to: params.dateTo,
                status: params.status,
                per_page: '100',
                page: '1',
            });
            if (params.search.trim()) urlParams.set('search', params.search.trim());
            if (params.minAmount !== '') urlParams.set('min_amount', params.minAmount);
            if (params.maxAmount !== '') urlParams.set('max_amount', params.maxAmount);

            const res = await apiFetch(`/api/v1/reports/orders?${urlParams.toString()}`);
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
    }, [token, params.dateFrom, params.dateTo, params.search, params.minAmount, params.maxAmount, params.status]);

    useEffect(() => {
        void fetchOrders();
        const interval = setInterval(() => void fetchOrders(), POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, [fetchOrders]);

    return { orders, summary, period, loading, error, refresh: fetchOrders, lastUpdated };
}

export function todayBogota(): string {
    return new Date().toLocaleDateString('en-CA', { timeZone: 'America/Bogota' });
}
