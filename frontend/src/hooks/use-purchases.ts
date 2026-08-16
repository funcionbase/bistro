import { apiFetch } from '@/lib/api';
import type {
    PurchaseFilters,
    PurchaseOrderAttachment,
    PurchaseOrderCreatePayload,
    PurchaseOrderDetail,
    PurchaseOrderListResponse,
    PurchaseOrderUpdatePayload,
    PurchasePaymentMethod,
} from '@/types/purchases';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useState } from 'react';

export const DEFAULT_PURCHASE_FILTERS: PurchaseFilters = {
    q: '',
    status: '',
    supplier_id: null,
    pending_refund: false,
};

export function usePurchases(token: string | null) {
    const queryClient = useQueryClient();
    const [filters, setFiltersState] = useState<PurchaseFilters>(DEFAULT_PURCHASE_FILTERS);

    const setFilters = useCallback((next: Partial<PurchaseFilters>) => {
        setFiltersState((prev) => ({ ...prev, ...next }));
    }, []);

    // Listado principal vía React Query (Fase 3): cache + dedupe +
    // `keepPreviousData` — la tabla no se blanquea al cambiar filtros ni al
    // revisitar; el skeleton completo solo en el primer load sin cache.
    const query = useQuery<PurchaseOrderListResponse, Error>({
        queryKey: ['purchases', 'orders', filters],
        enabled: !!token,
        placeholderData: keepPreviousData,
        queryFn: async ({ signal }) => {
            const params = new URLSearchParams({ per_page: '50' });
            if (filters.q) params.set('q', filters.q);
            if (filters.status) params.set('status', filters.status);
            if (filters.supplier_id) params.set('supplier_id', String(filters.supplier_id));
            if (filters.pending_refund) params.set('pending_refund', '1');
            const res = await apiFetch(`/api/v1/purchases?${params.toString()}`, { signal });
            const json = (await res.json()) as PurchaseOrderListResponse | { message?: string };
            if (!res.ok) {
                throw new Error((json as { message?: string }).message ?? 'Error al cargar compras.');
            }
            return json as PurchaseOrderListResponse;
        },
    });

    const orders = query.data?.data ?? [];
    const loading = query.isLoading;
    const error = query.isError ? (query.error?.message ?? 'Error de conexión.') : null;

    const fetchOrders = useCallback(async () => {
        await queryClient.invalidateQueries({ queryKey: ['purchases', 'orders'] });
    }, [queryClient]);

    const fetchOrder = useCallback(async (id: string): Promise<PurchaseOrderDetail> => {
        const res = await apiFetch(`/api/v1/purchases/${id}`);
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as PurchaseOrderDetail;
    }, []);

    const createOrder = useCallback(async (payload: PurchaseOrderCreatePayload) => {
        const res = await apiFetch('/api/v1/purchases', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as PurchaseOrderDetail;
    }, []);

    const updateOrder = useCallback(async (id: string, payload: PurchaseOrderUpdatePayload) => {
        const res = await apiFetch(`/api/v1/purchases/${id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as PurchaseOrderDetail;
    }, []);

    async function postAction(id: string, action: string, body?: object) {
        const res = await apiFetch(`/api/v1/purchases/${id}/${action}`, {
            method: 'POST',
            headers: body ? { 'Content-Type': 'application/json' } : undefined,
            body: body ? JSON.stringify(body) : undefined,
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as PurchaseOrderDetail;
    }

    const submitOrder = useCallback((id: string) => postAction(id, 'submit'), []);
    const receiveOrder = useCallback((id: string) => postAction(id, 'receive'), []);
    const payOrder = useCallback(
        (id: string, body: { payment_method: PurchasePaymentMethod; payment_reference?: string }) => postAction(id, 'pay', body),
        [],
    );
    const cancelOrder = useCallback((id: string, reason?: string) => postAction(id, 'cancel', { reason }), []);
    const voidOrder = useCallback((id: string, reason: string) => postAction(id, 'void', { reason }), []);
    const settleRefund = useCallback((id: string, reference?: string) => postAction(id, 'settle-refund', { reference }), []);

    const uploadAttachment = useCallback(async (id: string, file: File, type: string): Promise<PurchaseOrderAttachment> => {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('type', type);
        const res = await apiFetch(`/api/v1/purchases/${id}/attachments`, { method: 'POST', body: fd });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as PurchaseOrderAttachment;
    }, []);

    const deleteAttachment = useCallback(async (id: string, attachmentId: string) => {
        const res = await apiFetch(`/api/v1/purchases/${id}/attachments/${attachmentId}`, { method: 'DELETE' });
        if (!res.ok) throw await res.json();
    }, []);

    // Pide al backend una URL temporal (S3 presigned, TTL 10 min) para
    // previsualizar (`inline`) o descargar (`attachment`) el adjunto. El archivo
    // vive en S3; el cliente nunca arma la URL del bucket.
    const attachmentUrl = useCallback(async (id: string, attachmentId: string, disposition: 'inline' | 'attachment' = 'inline'): Promise<string> => {
        const res = await apiFetch(`/api/v1/purchases/${id}/attachments/${attachmentId}/url?disposition=${disposition}`);
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data.url as string;
    }, []);

    return {
        orders,
        loading,
        error,
        filters,
        setFilters,
        fetchOrders,
        fetchOrder,
        createOrder,
        updateOrder,
        submitOrder,
        receiveOrder,
        payOrder,
        cancelOrder,
        voidOrder,
        settleRefund,
        uploadAttachment,
        deleteAttachment,
        attachmentUrl,
    };
}
