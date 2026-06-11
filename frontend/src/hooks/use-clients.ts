import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';

export type ClientSegment = 'vip' | 'recurrent' | 'new' | 'inactive' | 'at_risk' | 'regular';

export interface ClientListItem {
    id: string;
    phone: string | null;
    name: string | null;
    kind: 'natural' | 'company' | null;
    doc_type: string | null;
    doc_number: string | null;
    email: string | null;
    dian_complete: boolean;
    total_orders: number;
    completed_orders: number;
    cancelled_orders: number;
    total_spent: number;
    average_ticket: number;
    first_order_at: string | null;
    last_order_at: string | null;
    orders_last_60d: number;
    spent_last_90d: number;
    cancellation_rate: number;
    tags: string[];
    segment: ClientSegment;
}

export interface ClientListMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    segments: ClientSegment[];
    available_tags: string[];
}

export interface ClientListFilters {
    search?: string;
    segment?: ClientSegment | '';
    tag?: string;
    page?: number;
    per_page?: number;
}

interface UseClientsReturn {
    clients: ClientListItem[];
    meta: ClientListMeta | null;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
}

export function useClients(token: string | null, filters: ClientListFilters): UseClientsReturn {
    const [clients, setClients] = useState<ClientListItem[]>([]);
    const [meta, setMeta] = useState<ClientListMeta | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const isMounted = useRef(true);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const fetchClients = useCallback(async (): Promise<void> => {
        if (!token) return;
        const params = new URLSearchParams();
        if (filters.search) params.set('search', filters.search);
        if (filters.segment) params.set('segment', filters.segment);
        if (filters.tag) params.set('tag', filters.tag);
        if (filters.page) params.set('page', String(filters.page));
        if (filters.per_page) params.set('per_page', String(filters.per_page));

        try {
            setLoading(true);
            const res = await apiFetch(`/api/v1/clients${params.toString() ? `?${params}` : ''}`);
            if (!isMounted.current) return;
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'Error al cargar contactos.');
                setLoading(false);
                return;
            }
            const json = (await res.json()) as { data: ClientListItem[]; meta: ClientListMeta };
            setClients(json.data ?? []);
            setMeta(json.meta ?? null);
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token, filters.search, filters.segment, filters.tag, filters.page, filters.per_page]);

    useEffect(() => {
        void fetchClients();
    }, [fetchClients]);

    return { clients, meta, loading, error, refresh: fetchClients };
}
