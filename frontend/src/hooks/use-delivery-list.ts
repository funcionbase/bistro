import { apiFetch } from '@/lib/api';
import type { Delivery, DeliveryPagination } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface DeliveryFilters {
    status?: string;
    user_id?: string;
    date_from?: string;
    date_to?: string;
    page?: number;
}

interface UseDeliveryListReturn {
    pagination: DeliveryPagination | null;
    deliveries: Delivery[];
    loading: boolean;
    filters: DeliveryFilters;
    setFilters: (f: DeliveryFilters) => void;
    fetchDeliveries: () => Promise<void>;
    completeDelivery: (id: string) => Promise<void>;
    reassignDelivery: (id: string, newUserId: string, reason: string) => Promise<void>;
    completingId: string | null;
}

// Por defecto sin polling automatico — el caller decide cuando activarlo
// (idealmente via useLivePolling para que el operador opte explicitamente).
export function useDeliveryList(token: string | null, pollingMs = 0): UseDeliveryListReturn {
    const [pagination, setPagination] = useState<DeliveryPagination | null>(null);
    const [loading, setLoading] = useState(false);
    const [filters, setFilters] = useState<DeliveryFilters>({});
    const [completingId, setCompletingId] = useState<string | null>(null);
    const isMounted = useRef(true);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const fetchDeliveries = useCallback(async (): Promise<void> => {
        if (!token) return;
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (filters.status) params.set('status', filters.status);
            if (filters.user_id) params.set('user_id', String(filters.user_id));
            if (filters.date_from) params.set('date_from', filters.date_from);
            if (filters.date_to) params.set('date_to', filters.date_to);
            if (filters.page) params.set('page', String(filters.page));

            const res = await apiFetch(`/api/v1/deliveries?${params}`);
            if (!isMounted.current) return;
            if (res.ok) {
                const json = await res.json();
                setPagination(json);
            }
        } catch {
            // silently ignore
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token, filters]);

    useEffect(() => {
        void fetchDeliveries();
        if (!pollingMs) return;
        const interval = setInterval(() => void fetchDeliveries(), pollingMs);
        return () => clearInterval(interval);
    }, [fetchDeliveries, pollingMs]);

    const completeDelivery = useCallback(
        async (id: string): Promise<void> => {
            setCompletingId(id);
            try {
                const res = await apiFetch(`/api/v1/deliveries/${id}/complete`, { method: 'PATCH' });
                if (!res.ok) {
                    const json = await res.json();
                    throw new Error(json.message ?? 'Error al completar la entrega.');
                }
                await fetchDeliveries();
            } finally {
                if (isMounted.current) setCompletingId(null);
            }
        },
        [fetchDeliveries],
    );

    const reassignDelivery = useCallback(
        async (id: string, newUserId: string, reason: string): Promise<void> => {
            const res = await apiFetch(`/api/v1/deliveries/${id}/reassign`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: newUserId, reason }),
            });
            if (!res.ok) {
                const json = await res.json();
                throw new Error(json.message ?? 'Error al reasignar.');
            }
            await fetchDeliveries();
        },
        [fetchDeliveries],
    );

    return {
        pagination,
        deliveries: pagination?.data ?? [],
        loading,
        filters,
        setFilters,
        fetchDeliveries,
        completeDelivery,
        reassignDelivery,
        completingId,
    };
}
