import { apiFetch } from '@/lib/api';
import type { Supplier, SupplierFormPayload, SupplierListResponse } from '@/types/suppliers';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface SupplierFilters {
    q: string;
    archived: boolean;
}

export const DEFAULT_SUPPLIER_FILTERS: SupplierFilters = { q: '', archived: false };

export function useSuppliers(token: string | null) {
    const [suppliers, setSuppliers] = useState<Supplier[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [filters, setFiltersState] = useState<SupplierFilters>(DEFAULT_SUPPLIER_FILTERS);
    const isMounted = useRef(true);

    const setFilters = useCallback((next: Partial<SupplierFilters>) => {
        setFiltersState((prev) => ({ ...prev, ...next }));
    }, []);

    const fetchSuppliers = useCallback(async () => {
        if (!token) return;
        setLoading(true);
        const params = new URLSearchParams({ per_page: '200' });
        if (filters.q) params.set('q', filters.q);
        if (filters.archived) params.set('archived', '1');
        try {
            const res = await apiFetch(`/api/v1/suppliers?${params.toString()}`);
            const json = (await res.json()) as SupplierListResponse | { message?: string };
            if (!isMounted.current) return;
            if (!res.ok) {
                setError((json as { message?: string }).message ?? 'Error al cargar proveedores.');
                return;
            }
            setSuppliers((json as SupplierListResponse).data ?? []);
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token, filters]);

    useEffect(() => {
        isMounted.current = true;
        fetchSuppliers();
        return () => {
            isMounted.current = false;
        };
    }, [fetchSuppliers]);

    const createSupplier = useCallback(async (payload: SupplierFormPayload) => {
        const res = await apiFetch('/api/v1/suppliers', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as Supplier;
    }, []);

    const updateSupplier = useCallback(async (id: string, payload: Partial<SupplierFormPayload>) => {
        const res = await apiFetch(`/api/v1/suppliers/${id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as Supplier;
    }, []);

    const archiveSupplier = useCallback(async (id: string) => {
        const res = await apiFetch(`/api/v1/suppliers/${id}`, { method: 'DELETE' });
        if (!res.ok) throw await res.json();
    }, []);

    const restoreSupplier = useCallback(async (id: string) => {
        const res = await apiFetch(`/api/v1/suppliers/${id}/restore`, { method: 'POST' });
        if (!res.ok) throw await res.json();
    }, []);

    return {
        suppliers,
        loading,
        error,
        filters,
        setFilters,
        fetchSuppliers,
        createSupplier,
        updateSupplier,
        archiveSupplier,
        restoreSupplier,
    };
}
