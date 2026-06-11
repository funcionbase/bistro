import { apiFetch } from '@/lib/api';
import type {
    BusinessHour,
    BusinessHourException,
    BusinessHourExceptionFormData,
    BusinessHourFormData,
    RestaurantStatus,
} from '@/types/business-hours';
import { useCallback, useEffect, useRef, useState } from 'react';

interface UseBusinessHoursReturn {
    hours: BusinessHour[];
    exceptions: BusinessHourException[];
    status: RestaurantStatus | null;
    canUpdate: boolean;
    loading: boolean;
    error: string | null;
    fetchHours: () => Promise<void>;
    fetchExceptions: () => Promise<void>;
    fetchStatus: () => Promise<void>;
    updateHours: (data: BusinessHourFormData[]) => Promise<void>;
    createException: (data: BusinessHourExceptionFormData) => Promise<BusinessHourException>;
    updateException: (id: string, data: BusinessHourExceptionFormData) => Promise<BusinessHourException>;
    deleteException: (id: string) => Promise<void>;
}

export function useBusinessHours(token: string | null): UseBusinessHoursReturn {
    const [hours, setHours] = useState<BusinessHour[]>([]);
    const [exceptions, setExceptions] = useState<BusinessHourException[]>([]);
    const [status, setStatus] = useState<RestaurantStatus | null>(null);
    const [canUpdate, setCanUpdate] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const isMounted = useRef(true);

    const fetchHours = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/hours');
            const data = await res.json();
            if (!isMounted.current) return;
            if (!res.ok) {
                setError(data.message ?? 'Error al cargar horarios.');
                return;
            }
            setHours(data.data ?? []);
            setCanUpdate(data.can_update ?? false);
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        }
    }, [token]);

    const fetchExceptions = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/hours/exceptions');
            const data = await res.json();
            if (!isMounted.current) return;
            if (!res.ok) return;
            setExceptions(data.data ?? []);
        } catch {
            // silent
        }
    }, [token]);

    const fetchStatus = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/hours/status');
            const data = await res.json();
            if (!isMounted.current) return;
            if (res.ok) setStatus(data.data ?? null);
        } catch {
            // silent
        }
    }, [token]);

    useEffect(() => {
        isMounted.current = true;
        Promise.all([fetchHours(), fetchExceptions(), fetchStatus()]).finally(() => {
            if (isMounted.current) setLoading(false);
        });
        return () => {
            isMounted.current = false;
        };
    }, [fetchHours, fetchExceptions, fetchStatus]);

    const updateHours = useCallback(async (data: BusinessHourFormData[]): Promise<void> => {
        const res = await apiFetch('/api/v1/hours', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ hours: data }),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        setHours(json.data ?? []);
    }, []);

    const createException = useCallback(async (data: BusinessHourExceptionFormData): Promise<BusinessHourException> => {
        const res = await apiFetch('/api/v1/hours/exceptions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data;
    }, []);

    const updateException = useCallback(async (id: string, data: BusinessHourExceptionFormData): Promise<BusinessHourException> => {
        const res = await apiFetch(`/api/v1/hours/exceptions/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data;
    }, []);

    const deleteException = useCallback(async (id: string): Promise<void> => {
        const res = await apiFetch(`/api/v1/hours/exceptions/${id}`, { method: 'DELETE' });
        if (!res.ok) {
            const json = await res.json();
            throw json;
        }
    }, []);

    return {
        hours,
        exceptions,
        status,
        canUpdate,
        loading,
        error,
        fetchHours,
        fetchExceptions,
        fetchStatus,
        updateHours,
        createException,
        updateException,
        deleteException,
    };
}
