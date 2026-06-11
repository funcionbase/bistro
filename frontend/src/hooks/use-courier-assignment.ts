import { apiFetch } from '@/lib/api';
import type { Courier } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

interface UseCourierAssignmentReturn {
    couriers: Courier[];
    loading: boolean;
    error: string | null;
    fetchCouriers: () => Promise<void>;
    assignCourier: (orderId: string, courierId: string, reason?: string) => Promise<void>;
}

export function useCourierAssignment(token: string | null): UseCourierAssignmentReturn {
    const [couriers, setCouriers] = useState<Courier[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const isMounted = useRef(true);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const fetchCouriers = useCallback(async (): Promise<void> => {
        if (!token) return;
        setLoading(true);
        setError(null);
        try {
            const res = await apiFetch('/api/v1/deliveries/couriers');
            const data = await res.json();
            if (!isMounted.current) return;
            if (!res.ok) {
                setError(data.message ?? 'Error al cargar repartidores.');
                return;
            }
            setCouriers(data.data ?? []);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token]);

    const assignCourier = useCallback(async (orderId: string, courierId: string, reason?: string): Promise<void> => {
        const res = await apiFetch(`/api/v1/orders/${orderId}/assign-courier`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: courierId, reason }),
        });
        if (!res.ok) {
            const json = await res.json();
            throw new Error(json.message ?? 'Error al asignar repartidor.');
        }
    }, []);

    return { couriers, loading, error, fetchCouriers, assignCourier };
}
