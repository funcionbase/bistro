import type { KanbanOrder } from '@/hooks/use-orders';
import { apiFetch } from '@/lib/api';
import type { AvailableDeliverer } from '@/types';
import { useCallback, useState } from 'react';

interface ReassignContext {
    orderId: string;
    deliveryId: string;
    currentUserId: string;
}

interface UseDaySalesActionsArgs {
    /** Órdenes visibles (necesario para resolver el delivery al reasignar). */
    orders: KanbanOrder[];
    /** Refresca la lista tras una mutación. */
    refresh: () => Promise<void> | void;
}

interface UseDaySalesActionsReturn {
    orderDetail: KanbanOrder | null;
    setOrderDetail: (order: KanbanOrder | null) => void;
    assignOrderId: string | null;
    setAssignOrderId: (id: string | null) => void;
    reassignContext: ReassignContext | null;
    setReassignContext: (ctx: ReassignContext | null) => void;
    refundOrderId: string | null;
    setRefundOrderId: (id: string | null) => void;
    deliverers: AvailableDeliverer[];
    loadingDeliverers: boolean;
    submitting: boolean;
    openOrderDetail: (orderId: string) => Promise<void>;
    openAssign: (orderId: string) => Promise<void>;
    openReassign: (orderId: string, deliveryId: string) => Promise<void>;
    handleAssign: (userId: string, reason: string) => Promise<void>;
    handleReassign: (userId: string, reason: string) => Promise<void>;
}

/**
 * Centraliza el manejo de estado y data-fetching de las acciones
 * contextuales de la página de ventas del día: ver detalle de orden,
 * asignar/reasignar repartidor y disparar la devolución. El comportamiento
 * es idéntico al que vivía inline en la página — solo se movió de archivo.
 */
export function useDaySalesActions({ orders, refresh }: UseDaySalesActionsArgs): UseDaySalesActionsReturn {
    const [orderDetail, setOrderDetail] = useState<KanbanOrder | null>(null);
    const [assignOrderId, setAssignOrderId] = useState<string | null>(null);
    const [reassignContext, setReassignContext] = useState<ReassignContext | null>(null);
    const [deliverers, setDeliverers] = useState<AvailableDeliverer[]>([]);
    const [loadingDeliverers, setLoadingDeliverers] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [refundOrderId, setRefundOrderId] = useState<string | null>(null);

    const openOrderDetail = useCallback(async (orderId: string) => {
        try {
            const res = await apiFetch(`/api/v1/orders/${orderId}`);
            if (!res.ok) return;
            const body = await res.json();
            setOrderDetail(body.data ?? null);
        } catch {
            // noop
        }
    }, []);

    const fetchDeliverers = useCallback(async (orderId: string) => {
        setLoadingDeliverers(true);
        try {
            const res = await apiFetch(`/api/v1/orders/${orderId}/available-deliverers`);
            if (!res.ok) return;
            const body = await res.json();
            setDeliverers(body.data ?? []);
        } finally {
            setLoadingDeliverers(false);
        }
    }, []);

    const openAssign = useCallback(
        async (orderId: string) => {
            setAssignOrderId(orderId);
            await fetchDeliverers(orderId);
        },
        [fetchDeliverers],
    );

    const openReassign = useCallback(
        async (orderId: string, deliveryId: string) => {
            const order = orders.find((o) => o.id === orderId);
            if (!order?.delivery) return;
            setReassignContext({
                orderId,
                deliveryId,
                currentUserId: order.delivery.deliverer?.id ?? '',
            });
            await fetchDeliverers(orderId);
        },
        [orders, fetchDeliverers],
    );

    const handleAssign = useCallback(
        async (userId: string, reason: string) => {
            if (!assignOrderId) return;
            setSubmitting(true);
            try {
                const res = await apiFetch('/api/v1/deliveries', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order_id: assignOrderId, user_id: userId, reason: reason || undefined }),
                });
                if (res.ok) {
                    setAssignOrderId(null);
                    await refresh();
                    if (orderDetail?.id === assignOrderId) await openOrderDetail(assignOrderId);
                }
            } finally {
                setSubmitting(false);
            }
        },
        [assignOrderId, refresh, orderDetail, openOrderDetail],
    );

    const handleReassign = useCallback(
        async (userId: string, reason: string) => {
            if (!reassignContext) return;
            setSubmitting(true);
            try {
                const res = await apiFetch(`/api/v1/deliveries/${reassignContext.deliveryId}/reassign`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: userId, reason: reason || undefined }),
                });
                if (res.ok) {
                    setReassignContext(null);
                    await refresh();
                    if (orderDetail?.id === reassignContext.orderId) await openOrderDetail(reassignContext.orderId);
                }
            } finally {
                setSubmitting(false);
            }
        },
        [reassignContext, refresh, orderDetail, openOrderDetail],
    );

    return {
        orderDetail,
        setOrderDetail,
        assignOrderId,
        setAssignOrderId,
        reassignContext,
        setReassignContext,
        refundOrderId,
        setRefundOrderId,
        deliverers,
        loadingDeliverers,
        submitting,
        openOrderDetail,
        openAssign,
        openReassign,
        handleAssign,
        handleReassign,
    };
}
