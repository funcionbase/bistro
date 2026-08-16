import { apiFetch } from '@/lib/api';
import type {
    Ingredient,
    IngredientFormPayload,
    IngredientListMeta,
    IngredientListResponse,
    IngredientMovement,
    MovementResponse,
    TransferPayload,
} from '@/types/inventory';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useState } from 'react';

export interface InventoryFilters {
    q: string;
    category: string;
    low_stock: boolean;
    archived: boolean;
    warehouse_id: string;
}

export const DEFAULT_FILTERS: InventoryFilters = {
    q: '',
    category: '',
    low_stock: false,
    archived: false,
    warehouse_id: '',
};

interface UseInventoryReturn {
    ingredients: Ingredient[];
    meta: IngredientListMeta;
    loading: boolean;
    error: string | null;
    filters: InventoryFilters;
    setFilters: (next: Partial<InventoryFilters>) => void;
    fetchIngredients: () => Promise<void>;
    createIngredient: (payload: IngredientFormPayload) => Promise<Ingredient>;
    updateIngredient: (id: string, payload: Partial<IngredientFormPayload>) => Promise<Ingredient>;
    archiveIngredient: (id: string) => Promise<void>;
    restoreIngredient: (id: string) => Promise<void>;
    recordEntry: (
        id: string,
        body: { quantity: number; unit_cost: number; reference?: string; warehouse_id?: string },
    ) => Promise<MovementResponse['data']>;
    recordWaste: (id: string, body: { quantity: number; reference: string; warehouse_id?: string }) => Promise<MovementResponse['data']>;
    recordAdjustment: (id: string, body: { quantity: number; reference: string; warehouse_id?: string }) => Promise<MovementResponse['data']>;
    fetchMovements: (id: string, page?: number) => Promise<{ data: IngredientMovement[]; pagination: IngredientListResponse['pagination'] }>;
    transfer: (payload: TransferPayload) => Promise<{
        reference: string;
        out: { id: string; warehouse_id: string; dest_warehouse_id: string; quantity: string };
        in: { id: string; warehouse_id: string; dest_warehouse_id: string; quantity: string };
    }>;
}

const EMPTY_META: IngredientListMeta = { low_stock_count: 0, categories: [] };

export function useInventory(token: string | null): UseInventoryReturn {
    const queryClient = useQueryClient();
    const [filters, setFiltersState] = useState<InventoryFilters>(DEFAULT_FILTERS);

    const setFilters = useCallback((next: Partial<InventoryFilters>) => {
        setFiltersState((prev) => ({ ...prev, ...next }));
    }, []);

    // Listado principal vía React Query (Fase 3): cache + dedupe +
    // `keepPreviousData` para que al cambiar filtros o revisitar la página no
    // se blanquee la tabla ni parpadee el skeleton — la data previa se mantiene
    // mientras llega la nueva. El skeleton completo solo aparece en el primer
    // load sin cache (`isLoading`).
    const query = useQuery<IngredientListResponse, Error>({
        queryKey: ['inventory', 'ingredients', filters],
        enabled: !!token,
        placeholderData: keepPreviousData,
        queryFn: async ({ signal }) => {
            const params = new URLSearchParams();
            params.set('per_page', '200');
            if (filters.q) params.set('q', filters.q);
            if (filters.category) params.set('category', filters.category);
            if (filters.low_stock) params.set('low_stock', '1');
            if (filters.archived) params.set('archived', '1');
            if (filters.warehouse_id) params.set('warehouse_id', filters.warehouse_id);
            const res = await apiFetch(`/api/v1/inventory/ingredients?${params.toString()}`, { signal });
            const json = (await res.json()) as IngredientListResponse | { message?: string };
            if (!res.ok) {
                throw new Error((json as { message?: string }).message ?? 'Error al cargar inventario.');
            }
            return json as IngredientListResponse;
        },
    });

    const ingredients = query.data?.data ?? [];
    const meta = query.data?.meta ?? EMPTY_META;
    const loading = query.isLoading;
    const error = query.isError ? (query.error?.message ?? 'Error de conexión.') : null;

    // Tras una mutación, las páginas llaman a `fetchIngredients()` para refrescar.
    // Invalidar (no `refetch`) deja identidad estable y cubre cualquier filtro.
    const fetchIngredients = useCallback(async () => {
        await queryClient.invalidateQueries({ queryKey: ['inventory', 'ingredients'] });
    }, [queryClient]);

    const createIngredient = useCallback(async (payload: IngredientFormPayload) => {
        const res = await apiFetch('/api/v1/inventory/ingredients', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as Ingredient;
    }, []);

    const updateIngredient = useCallback(async (id: string, payload: Partial<IngredientFormPayload>) => {
        const res = await apiFetch(`/api/v1/inventory/ingredients/${id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as Ingredient;
    }, []);

    const archiveIngredient = useCallback(async (id: string) => {
        const res = await apiFetch(`/api/v1/inventory/ingredients/${id}`, { method: 'DELETE' });
        if (!res.ok) {
            const json = await res.json();
            throw json;
        }
    }, []);

    const restoreIngredient = useCallback(async (id: string) => {
        const res = await apiFetch(`/api/v1/inventory/ingredients/${id}/restore`, { method: 'POST' });
        if (!res.ok) {
            const json = await res.json();
            throw json;
        }
    }, []);

    const postMovement = useCallback(async (id: string, kind: 'entry' | 'waste' | 'adjustment', body: object) => {
        const res = await apiFetch(`/api/v1/inventory/ingredients/${id}/movements/${kind}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return (json as MovementResponse).data;
    }, []);

    const recordEntry = useCallback(
        (id: string, body: { quantity: number; unit_cost: number; reference?: string; warehouse_id?: string }) => postMovement(id, 'entry', body),
        [postMovement],
    );

    const recordWaste = useCallback(
        (id: string, body: { quantity: number; reference: string; warehouse_id?: string }) => postMovement(id, 'waste', body),
        [postMovement],
    );

    const recordAdjustment = useCallback(
        (id: string, body: { quantity: number; reference: string; warehouse_id?: string }) => postMovement(id, 'adjustment', body),
        [postMovement],
    );

    const fetchMovements = useCallback(async (id: string, page = 1) => {
        const res = await apiFetch(`/api/v1/inventory/ingredients/${id}/movements?page=${page}`);
        const json = await res.json();
        if (!res.ok) throw json;
        return json as { data: IngredientMovement[]; pagination: IngredientListResponse['pagination'] };
    }, []);

    const transfer = useCallback(async (payload: TransferPayload) => {
        const res = await apiFetch('/api/v1/inventory/transfers', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as {
            reference: string;
            out: { id: string; warehouse_id: string; dest_warehouse_id: string; quantity: string };
            in: { id: string; warehouse_id: string; dest_warehouse_id: string; quantity: string };
        };
    }, []);

    return {
        ingredients,
        meta,
        loading,
        error,
        filters,
        setFilters,
        fetchIngredients,
        createIngredient,
        updateIngredient,
        archiveIngredient,
        restoreIngredient,
        recordEntry,
        recordWaste,
        recordAdjustment,
        fetchMovements,
        transfer,
    };
}
