import { DianOrderActions } from '@/components/dian/dian-order-actions';
import OrderNotesSection from '@/components/orders/order-notes-section';
import { OrderStatusBadge } from '@/components/orders/order-status-badge';
import { TablePaymentSheet } from '@/components/order-tables/table-payment-sheet';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { BatchApprovalCard } from '@/components/ui/batch-approval-card';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Button } from '@/components/ui/button';
import { CancellationRequestCard } from '@/components/ui/cancellation-request-card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DetailRow } from '@/components/ui/detail-row';
import { GuestBadge } from '@/components/ui/guest-badge';
import { GuestItemList } from '@/components/ui/guest-item-list';
import { Input } from '@/components/ui/input';
import { KpiCell } from '@/components/ui/kpi-cell';
import { Label } from '@/components/ui/label';
import { NotesEditor } from '@/components/ui/notes-editor';
import { OrderItemCard, type OrderItemStatus } from '@/components/ui/order-item-card';
import { PageHeader } from '@/components/ui/page-header';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SplitPaymentSheet } from '@/components/ui/split-payment-sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCashRegister } from '@/hooks/use-cash-register';
import type { KanbanOrder } from '@/hooks/use-orders';
import { useTablePayment } from '@/hooks/use-table-payment';
import type { ClosePaymentInput, TableOrder } from '@/hooks/use-tables';
import { usePaymentMethods } from '@/hooks/use-payment-methods';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { formatOrderTypeLabel } from '@/lib/order-type';
import { shortOrderCode } from '@/lib/order-code';
import { useSharedData } from '@/lib/shared-data';
import type { PaymentMethod } from '@/types';
import { AlertCircle, CheckCircle2, ChefHat, Clock, RefreshCw, X } from 'lucide-react';
import { AssignCourierModal } from '@/components/deliveries/assign-courier-modal';
import { RefundOrderModal } from '@/components/deliveries/refund-order-modal';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useCourierAssignment } from '@/hooks/use-courier-assignment';
import { usePermissions } from '@/hooks/use-permissions';
import { ComponentProps, useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { formatCurrency } from '@/lib/formatters';
import { maskPhone } from '@/lib/phone';
import { formatDateTime, formatDateTimeShort } from '@/lib/datetime';
import { sanitizePlainText } from '@/lib/input-sanitize';

// ── Session / cashier data types ──────────────────────────────────────────────

interface Guest {
    id: string;
    display_name: string;
    phone: string;
    joined_at: string | null;
}

interface ItemForBatch {
    id: string;
    name: string;
    unit_price: string;
    quantity: number;
    notes: string | null;
    status: OrderItemStatus;
    cancellation_reason: string | null;
    guest_id: string | null;
}

interface PendingBatch {
    guest_id: string;
    guest_name: string;
    submitted_at: string | null;
    items: ItemForBatch[];
}

interface CancellationRequestItem {
    id: string;
    order_item_id: string;
    guest_id: string;
    status: 'pending' | 'approved' | 'denied';
    reason: string | null;
}

interface GroupNote {
    id: string;
    scope: 'group' | 'kitchen_alert';
    body: string;
}

interface SessionDetail {
    id: string;
    status: 'open' | 'locked' | 'closed' | 'expired';
    guests: Guest[];
    pending_batches: PendingBatch[];
    items_by_status: Record<OrderItemStatus, ItemForBatch[]>;
    group_notes: GroupNote[];
    cancellation_requests: CancellationRequestItem[];
}

interface ItemForCashier {
    id: string;
    name: string;
    quantity: number;
    unit_price: string;
    subtotal: string;
    status: string;
    paid_at: string | null;
    paid_receipt_id: string | null;
    refunded_at?: string | null;
}

interface GuestBreakdown {
    id: string | null;
    display_name: string;
    phone: string;
    subtotal: string;
    unpaid_amount: string;
    items: ItemForCashier[];
}

interface ReceiptSummary {
    id: string;
    payment_method: string;
    amount: string;
    reference: string | null;
    paid_at: string | null;
}

interface OrderSummaryItem {
    id: string;
    status: string;
    total: string;
    ordered_at: string | null;
}

interface TimelineEvent {
    at: string;
    action: string;
    label: string;
    detail: string | null;
    order_id: string | null;
    duration_seconds: number | null;
}

interface CashierState {
    order: { id: string; status: string; total: string; tip_amount: string } | null;
    session: { id: string; status: string; table_number: string | null; order_type: string | null };
    orders: OrderSummaryItem[];
    guests: GuestBreakdown[];
    unpaid_total: string;
    paid_total: string;
    tip_total: string;
    receipts: ReceiptSummary[];
}

// ── Constants ─────────────────────────────────────────────────────────────────

const ADVANCE_STATUSES = [
    { key: 'pending', label: 'Pendiente', rank: 1 },
    { key: 'in_kitchen', label: 'En cocina', rank: 2 },
    { key: 'ready', label: 'Para entrega', rank: 3 },
    { key: 'in_transit', label: 'En tránsito', rank: 4 },
    { key: 'completed', label: 'Completado', rank: 5 },
] as const;

const TERMINAL_STATUSES = new Set(['completed', 'cancelled', 'refunded']);

const ITEM_STATUS_TABS = [
    { key: 'pending_approval' as OrderItemStatus, label: 'Por aprobar' },
    { key: 'approved' as OrderItemStatus, label: 'Aprobados' },
    { key: 'in_kitchen' as OrderItemStatus, label: 'Cocina' },
    { key: 'ready' as OrderItemStatus, label: 'Listos' },
    { key: 'served' as OrderItemStatus, label: 'Servidos' },
    { key: 'cancelled' as OrderItemStatus, label: 'Cancelados' },
];

function rankOf(status: string): number {
    return ADVANCE_STATUSES.find((e) => e.key === status)?.rank ?? 0;
}

// ── Main component ────────────────────────────────────────────────────────────

export default function OrderShow() {
    const orderId = window.location.pathname.split('/').pop() ?? '';
    const navigate = useNavigate();
    const token = useToken();
    const { session: cashSession, selectedRegister } = useCashRegister(token);
    const { auth } = useSharedData();
    const paymentCatalog = usePaymentMethods();
    const cashierName = auth.user.first_name
        ? `${auth.user.first_name} ${auth.user.last_name ?? ''}`.trim()
        : auth.user.name;

    const { has } = usePermissions();
    const canUpdateOrders = has('orders.update');
    const canDeleteOrders = has('orders.delete');
    const canAssignCourier = has('deliveries.create');
    const canReassignCourier = has('deliveries.update');

    const { couriers, loading: couriersLoading, fetchCouriers, assignCourier } = useCourierAssignment(token);

    void navigate; // navigate used indirectly via browser — kept for future back button

    // ── Non-QR payment ────────────────────────────────────────────────────────
    const [companyQrUrl, setCompanyQrUrl] = useState<string | null>(null);
    useEffect(() => {
        let cancelled = false;
        void apiFetch('/api/v1/company')
            .then((r) => (r.ok ? r.json() : null))
            .then((j: { company?: { qr_code_path?: string | null } } | null) => {
                if (!cancelled)
                    setCompanyQrUrl(j?.company?.qr_code_path ? `/storage/${j.company.qr_code_path}` : null);
            })
            .catch(() => undefined);
        return () => {
            cancelled = true;
        };
    }, []);

    const closeWithPaymentDirect = useCallback(
        async (orderId: string, payload: ClosePaymentInput): Promise<{ queued: boolean }> => {
            const resp = await apiFetch(`/api/v1/orders/${orderId}/close-with-payment`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Error al cobrar la orden.');
            }
            return { queued: false };
        },
        [],
    );

    // ── Core state ────────────────────────────────────────────────────────────
    const [order, setOrder] = useState<KanbanOrder | null>(null);
    const [sessionDetail, setSessionDetail] = useState<SessionDetail | null>(null);
    const [cashierState, setCashierState] = useState<CashierState | null>(null);
    const [timeline, setTimeline] = useState<TimelineEvent[] | null>(null);
    const [loading, setLoading] = useState(true);
    const [orderError, setOrderError] = useState<string | null>(null);

    // ── Action state ──────────────────────────────────────────────────────────
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [statusError, setStatusError] = useState<string | null>(null);

    // ── Payment UI state ──────────────────────────────────────────────────────
    const [selectedByGuest, setSelectedByGuest] = useState<Record<string, Record<string, boolean>>>({});
    const [paySheet, setPaySheet] = useState<
        | { kind: 'partial'; guestId: string | null; itemIds: string[]; amount: string }
        | { kind: 'all'; amount: string }
        | null
    >(null);
    const [refundItem, setRefundItem] = useState<{
        itemId: string;
        guestId: string;
        amount: string;
        name: string;
    } | null>(null);
    const [refundMethod, setRefundMethod] = useState<PaymentMethod>('cash');
    const [refundReference, setRefundReference] = useState('');

    // ── Waiter note / close empty state ──────────────────────────────────────
    const [closeEmptyOpen, setCloseEmptyOpen] = useState(false);
    const [waiterNoteOpen, setWaiterNoteOpen] = useState(false);
    const [waiterNoteScope, setWaiterNoteScope] = useState<'group' | 'kitchen_alert'>('group');
    const [waiterNoteBody, setWaiterNoteBody] = useState('');

    const [cancelOpen, setCancelOpen] = useState(false);
    const [refundModalOpen, setRefundModalOpen] = useState(false);
    const [assignCourierOpen, setAssignCourierOpen] = useState(false);

    // ── Assign table state ────────────────────────────────────────────────────
    const [assignTableOpen, setAssignTableOpen] = useState(false);
    const [availableTables, setAvailableTables] = useState<{ id: string; number: string; capacity: number }[]>([]);
    const [selectedTableId, setSelectedTableId] = useState('');
    const [assignTableBusy, setAssignTableBusy] = useState(false);
    const [assignTableError, setAssignTableError] = useState<string | null>(null);

    // ── Data fetching ─────────────────────────────────────────────────────────

    const fetchSessionData = useCallback(async (sessionId: string) => {
        const [detailResp, cashierResp, timelineResp] = await Promise.allSettled([
            apiFetch(`/api/v1/table-sessions/${sessionId}`),
            apiFetch(`/api/v1/caja/table-sessions/${sessionId}`),
            apiFetch(`/api/v1/caja/table-sessions/${sessionId}/timeline`),
        ]);
        if (detailResp.status === 'fulfilled' && detailResp.value.ok) {
            const json = (await detailResp.value.json()) as { data: SessionDetail };
            setSessionDetail(json.data);
        }
        if (cashierResp.status === 'fulfilled' && cashierResp.value.ok) {
            const json = (await cashierResp.value.json()) as { data: CashierState };
            setCashierState(json.data);
        }
        if (timelineResp.status === 'fulfilled' && timelineResp.value.ok) {
            const json = (await timelineResp.value.json()) as { data: TimelineEvent[] };
            if (json.data) setTimeline(json.data);
        }
    }, []);

    const fetchAll = useCallback(async () => {
        try {
            const resp = await apiFetch(`/api/v1/orders/${orderId}`);
            if (!resp.ok) {
                setOrderError('No pudimos cargar la orden.');
                return;
            }
            const json = (await resp.json()) as { data: KanbanOrder };
            const ord = json.data;
            setOrder(ord);
            setOrderError(null);
            if (ord.table_session_id) {
                await fetchSessionData(ord.table_session_id);
            }
        } catch (err) {
            setOrderError(err instanceof Error ? err.message : 'Error cargando la orden.');
        } finally {
            setLoading(false);
        }
    }, [orderId, fetchSessionData]);

    useEffect(() => {
        void fetchAll();
    }, [fetchAll]);

    // Polling para sesiones QR abiertas (solo sesión, no orden)
    useEffect(() => {
        const sid = order?.table_session_id;
        if (!sid) return;
        const sesStatus = sessionDetail?.status;
        if (sesStatus === 'closed' || sesStatus === 'expired') return;
        const interval = window.setInterval(() => void fetchSessionData(sid), 3000);
        return () => window.clearInterval(interval);
    }, [order?.table_session_id, sessionDetail?.status, fetchSessionData]);

    // ── Derived ───────────────────────────────────────────────────────────────

    const guestById = useMemo(() => {
        const map: Record<string, Guest> = {};
        for (const g of sessionDetail?.guests ?? []) {
            map[g.id] = g;
        }
        return map;
    }, [sessionDetail]);

    const isTerminal = TERMINAL_STATUSES.has(order?.status ?? '');
    const isQrSession = Boolean(order?.table_session_id);
    const sessionClosed = cashierState?.session.status === 'closed';

    // Build a TableOrder-compatible object so useTablePayment can work with KanbanOrder.
    const orderAsTableOrder: TableOrder | null = order
        ? {
              id: order.id,
              status: order.status,
              table_number: order.table_number ?? '',
              items: [],
              item_count: 0,
              total: order.total,
              client_phone: order.client_phone ?? null,
              ordered_at: order.ordered_at ?? null,
          }
        : null;

    const {
        paymentState,
        setPaymentState,
        tipParsed,
        expectedTotal,
        cashChange,
        openPayment,
        closePaymentModal,
        submitPayment: submitTablePayment,
        dianLookupClient,
    } = useTablePayment({
        selectedOrder: orderAsTableOrder,
        closeWithPayment: closeWithPaymentDirect,
        onPaid: () => {
            void fetchAll();
        },
        cashSessionId: cashSession?.id ?? null,
    });

    const advanceOptions = useMemo(() => {
        if (!order || isTerminal) return [];
        // pending_approval no está en el kanban: se resuelve con "Aprobar pedido"
        // (o cancelar), nunca con updateStatus — el backend lo rechaza.
        if (order.status === 'pending_approval') return [];
        const current = rankOf(order.status);
        return ADVANCE_STATUSES.filter(
            (e) => e.rank > current && (e.key !== 'in_transit' || order.order_type === 'delivery'),
        );
    }, [order, isTerminal]);

    // ── Assign table ──────────────────────────────────────────────────────────

    const openAssignTable = useCallback(async () => {
        try {
            const resp = await apiFetch('/api/v1/table-sessions/available-tables');
            if (resp.ok) {
                const json = (await resp.json()) as { data: { id: string; number: string; capacity: number }[] };
                setAvailableTables(json.data);
            }
        } catch {
            // show dialog anyway with empty list
        }
        setSelectedTableId('');
        setAssignTableError(null);
        setAssignTableOpen(true);
    }, []);

    const submitAssignTable = useCallback(async () => {
        const sid = order?.table_session_id;
        if (!sid || !selectedTableId) return;
        setAssignTableBusy(true);
        setAssignTableError(null);
        try {
            const resp = await apiFetch(`/api/v1/table-sessions/${sid}/assign-table`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ table_id: selectedTableId }),
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Error al asignar mesa.');
            }
            setAssignTableOpen(false);
            await fetchAll();
        } catch (err) {
            setAssignTableError(err instanceof Error ? err.message : 'Error al asignar mesa.');
        } finally {
            setAssignTableBusy(false);
        }
    }, [order?.table_session_id, selectedTableId, fetchAll]);

    // ── Session mutations (waiter) ────────────────────────────────────────────

    const mutateSession = useCallback(
        async (path: string, body?: Record<string, unknown>, method: 'POST' | 'PATCH' = 'POST') => {
            const sid = order?.table_session_id;
            if (!sid) return;
            setBusy(true);
            setActionError(null);
            try {
                const resp = await apiFetch(`/api/v1/table-sessions/${sid}/${path}`, {
                    method,
                    headers: { 'Content-Type': 'application/json' },
                    body: body ? JSON.stringify(body) : undefined,
                });
                if (!resp.ok) {
                    const data = (await resp.json().catch(() => ({}))) as { message?: string };
                    throw new Error(data.message ?? 'Acción rechazada.');
                }
                await fetchSessionData(sid);
            } catch (err) {
                setActionError(err instanceof Error ? err.message : 'Acción fallida.');
            } finally {
                setBusy(false);
            }
        },
        [order?.table_session_id, fetchSessionData],
    );

    const approveBatch = (itemIds: string[]) => void mutateSession('approve-batch', { item_ids: itemIds });
    const rejectItem = (itemId: string, reason?: string) => void mutateSession(`items/${itemId}/reject`, { reason: reason ?? 'Rechazado por mesero' });
    const cancelInKitchen = (itemId: string, reason: string) => void mutateSession(`items/${itemId}/cancel`, { reason });

    const resolveCancellation = async (crId: string, decision: 'approved' | 'denied') => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/cancellation-requests/${crId}/resolve`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ decision }),
            });
            if (!resp.ok) throw new Error('No pudimos resolver la solicitud.');
            const sid = order?.table_session_id;
            if (sid) await fetchSessionData(sid);
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error resolviendo cancelación.');
        } finally {
            setBusy(false);
        }
    };

    const submitWaiterNote = async () => {
        if (waiterNoteBody.trim() === '') return;
        await mutateSession('notes', { scope: waiterNoteScope, body: waiterNoteBody });
        setWaiterNoteOpen(false);
        setWaiterNoteBody('');
    };

    const closeEmpty = async () => {
        await mutateSession('close-empty');
        setCloseEmptyOpen(false);
    };

    // ── Payment mutations (cashier) ───────────────────────────────────────────

    const toggleItem = (guestKey: string, itemId: string) => {
        setSelectedByGuest((prev) => {
            const cur = prev[guestKey] ?? {};
            return { ...prev, [guestKey]: { ...cur, [itemId]: !cur[itemId] } };
        });
    };

    const selectAllUnpaid = (guest: GuestBreakdown) => {
        setSelectedByGuest((prev) => ({
            ...prev,
            [guest.id ?? 'ungrouped']: Object.fromEntries(
                guest.items.filter((i) => !i.paid_at).map((i) => [i.id, true]),
            ),
        }));
    };

    const openPayPartial = (guest: GuestBreakdown) => {
        const key = guest.id ?? 'ungrouped';
        const selected = selectedByGuest[key] ?? {};
        const itemIds = guest.items.filter((i) => !i.paid_at && selected[i.id]).map((i) => i.id);
        if (itemIds.length === 0) {
            setActionError('Selecciona al menos un item para cobrar.');
            return;
        }
        const subtotal = guest.items
            .filter((i) => itemIds.includes(i.id))
            .reduce((acc, i) => acc + Number.parseFloat(i.subtotal), 0);
        setPaySheet({ kind: 'partial', guestId: guest.id, itemIds, amount: subtotal.toFixed(2) });
    };

    const openPayAll = () => {
        if (!cashierState) return;
        setPaySheet({ kind: 'all', amount: cashierState.unpaid_total });
    };

    const submitPayment = async (input: {
        payment_method: PaymentMethod;
        amount: string;
        reference: string | null;
        tip_amount: number;
    }) => {
        const sid = order?.table_session_id;
        if (!paySheet || !sid) return;
        setBusy(true);
        setActionError(null);
        try {
            const body: Record<string, unknown> = {
                payment_method: input.payment_method,
                amount: input.amount,
                reference: input.reference,
                tip_amount: input.tip_amount,
                client_uuid: crypto.randomUUID(),
                cash_session_id: cashSession?.id ?? null,
            };
            let url: string;
            if (paySheet.kind === 'partial') {
                url = `/api/v1/caja/table-sessions/${sid}/pay-partial`;
                body.guest_id = paySheet.guestId;
                body.item_ids = paySheet.itemIds;
            } else {
                url = `/api/v1/caja/table-sessions/${sid}/pay-all`;
            }
            const resp = await apiFetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Cobro rechazado.');
            }
            await fetchSessionData(sid);
            setSelectedByGuest({});
            setPaySheet(null);
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error en el cobro.');
        } finally {
            setBusy(false);
        }
    };

    const submitRefund = async () => {
        const sid = order?.table_session_id;
        if (!refundItem || !sid) return;
        if (refundReference.trim() === '') {
            setActionError('La referencia del refund es obligatoria.');
            return;
        }
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/caja/table-sessions/${sid}/refund-item`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    item_id: refundItem.itemId,
                    payment_method: refundMethod,
                    amount: refundItem.amount,
                    reference: refundReference.trim(),
                    client_uuid: crypto.randomUUID(),
                    cash_session_id: cashSession?.id ?? null,
                }),
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Refund rechazado.');
            }
            await fetchSessionData(sid);
            setRefundItem(null);
            setRefundReference('');
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error en el refund.');
        } finally {
            setBusy(false);
        }
    };

    const cancelOrder = async () => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/orders/${orderId}/cancel`, { method: 'POST' });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'No se pudo cancelar la orden.');
            }
            await fetchAll();
            setCancelOpen(false);
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error cancelando la orden.');
        } finally {
            setBusy(false);
        }
    };

    const handleAssignCourier = async (courierId: string) => {
        await assignCourier(orderId, courierId);
        await fetchAll();
        setAssignCourierOpen(false);
    };

    // ── Aprobación de pedidos públicos sin mesa (pickup/delivery del QR de sede) ──

    const approveOrder = async () => {
        setBusy(true);
        setStatusError(null);
        try {
            const resp = await apiFetch(`/api/v1/orders/${orderId}/approve`, { method: 'POST' });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'No se pudo aprobar el pedido.');
            }
            await fetchAll();
        } catch (err) {
            setStatusError(err instanceof Error ? err.message : 'Error aprobando el pedido.');
        } finally {
            setBusy(false);
        }
    };

    // ── Status advance (non-QR orders) ────────────────────────────────────────

    const advanceStatus = async (status: string) => {
        setBusy(true);
        setStatusError(null);
        try {
            const resp = await apiFetch(`/api/v1/orders/${orderId}/status`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status }),
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'No se pudo actualizar el estado.');
            }
            setOrder((prev) => (prev ? { ...prev, status } : prev));
        } catch (err) {
            setStatusError(err instanceof Error ? err.message : 'Error actualizando estado.');
        } finally {
            setBusy(false);
        }
    };

    // ── Render helpers ────────────────────────────────────────────────────────

    const pageTitle = order
        ? formatOrderTypeLabel(order.order_type, order.table_number) || `Orden #${shortOrderCode(order.id)}`
        : 'Orden';

    // ── Render ────────────────────────────────────────────────────────────────

    return (
        <PageShell title={pageTitle}>
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {loading ? (
                    <div className="space-y-4">
                        <div className="h-16 animate-pulse rounded-2xl bg-muted" />
                        <div className="h-48 animate-pulse rounded-2xl bg-muted" />
                        <div className="h-32 animate-pulse rounded-2xl bg-muted" />
                    </div>
                ) : (
                    <>
                        <PageHeader
                            eyebrow={
                                isQrSession
                                    ? (selectedRegister?.name ?? 'Caja')
                                    : order?.order_type ?? 'Orden'
                            }
                            title={pageTitle}
                            description={buildPageDescription(order, cashierState, cashierName)}
                            actions={
                                <div className="flex flex-wrap items-center gap-2">
                                    {isQrSession && !sessionClosed && cashierState && Number.parseFloat(cashierState.unpaid_total) > 0 && (
                                        <GatedButton type="button" size="sm" onClick={openPayAll} disabled={busy} className="w-full sm:w-auto" allowed={canUpdateOrders}>
                                            Cobrar toda la mesa
                                        </GatedButton>
                                    )}
                                    {isQrSession && (
                                        <>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    const sid = order?.table_session_id;
                                                    if (sid) void fetchSessionData(sid);
                                                }}
                                                disabled={loading || busy}
                                                className="w-full sm:w-auto"
                                            >
                                                <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Refrescar
                                            </Button>
                                            <GatedButton
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setWaiterNoteOpen(true)}
                                                disabled={busy || sessionClosed}
                                                className="w-full sm:w-auto"
                                                allowed={canUpdateOrders}
                                            >
                                                Agregar nota
                                            </GatedButton>
                                            {!order?.table_number && !isTerminal && !sessionClosed && (
                                                <GatedButton
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => void openAssignTable()}
                                                    disabled={busy}
                                                    className="w-full sm:w-auto"
                                                    allowed={canUpdateOrders}
                                                >
                                                    Asignar mesa
                                                </GatedButton>
                                            )}
                                            <GatedButton
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setCloseEmptyOpen(true)}
                                                disabled={busy || sessionClosed}
                                                className="text-destructive hover:text-destructive w-full sm:w-auto"
                                                allowed={canUpdateOrders}
                                            >
                                                Cerrar mesa vacía
                                            </GatedButton>
                                        </>
                                    )}
                                    {/* Pedido público sin mesa esperando aprobación: el staff valida
                                        los datos (dirección incluida) y aprueba — pasa a "pending". */}
                                    {!isQrSession && order?.status === 'pending_approval' && (
                                        <GatedButton
                                            type="button"
                                            size="sm"
                                            onClick={() => void approveOrder()}
                                            disabled={busy}
                                            className="w-full sm:w-auto"
                                            allowed={canUpdateOrders}
                                        >
                                            Aprobar pedido
                                        </GatedButton>
                                    )}
                                    {!isQrSession && !isTerminal && order?.status !== 'pending_approval' && (
                                        <GatedButton
                                            type="button"
                                            size="sm"
                                            onClick={() => orderAsTableOrder && openPayment(orderAsTableOrder)}
                                            disabled={busy}
                                            className="w-full sm:w-auto"
                                            allowed={canUpdateOrders}
                                        >
                                            Cobrar
                                        </GatedButton>
                                    )}
                                    {!isQrSession && !isTerminal && advanceOptions.length > 0 && (
                                        <>
                                            {advanceOptions.map((opt) => (
                                                <GatedButton
                                                    key={opt.key}
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() => void advanceStatus(opt.key)}
                                                    disabled={busy}
                                                    className="w-full sm:w-auto"
                                                    allowed={canUpdateOrders}
                                                >
                                                    → {opt.label}
                                                </GatedButton>
                                            ))}
                                        </>
                                    )}
                                    {(!isQrSession || order?.status === 'pending_approval') && !isTerminal && (
                                        <GatedButton
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setCancelOpen(true)}
                                            disabled={busy}
                                            className="text-destructive hover:text-destructive w-full sm:w-auto"
                                            allowed={canDeleteOrders}
                                        >
                                            Cancelar orden
                                        </GatedButton>
                                    )}
                                    {!isQrSession && order?.status === 'completed' && (
                                        <GatedButton
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setRefundModalOpen(true)}
                                            disabled={busy}
                                            className="w-full sm:w-auto"
                                            allowed={canUpdateOrders}
                                        >
                                            Reembolsar
                                        </GatedButton>
                                    )}
                                    {order?.order_type === 'delivery' && !order.delivery?.deliverer && !isTerminal && (
                                        <GatedButton
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => { void fetchCouriers(); setAssignCourierOpen(true); }}
                                            disabled={busy}
                                            className="w-full sm:w-auto"
                                            allowed={canAssignCourier}
                                        >
                                            Asignar repartidor
                                        </GatedButton>
                                    )}
                                    {order?.order_type === 'delivery' && order.delivery?.deliverer && !isTerminal && (
                                        <GatedButton
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => { void fetchCouriers(); setAssignCourierOpen(true); }}
                                            disabled={busy}
                                            className="w-full sm:w-auto"
                                            allowed={canReassignCourier}
                                        >
                                            Reasignar repartidor
                                        </GatedButton>
                                    )}
                                </div>
                            }
                        />

                        {order && <DianOrderActions orderId={order.id} orderStatus={order.status} />}

                        {orderError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{orderError}</AlertDescription>
                            </Alert>
                        )}
                        {actionError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{actionError}</AlertDescription>
                            </Alert>
                        )}
                        {statusError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{statusError}</AlertDescription>
                            </Alert>
                        )}
                        {sessionClosed && (
                            <Alert>
                                <CheckCircle2 className="h-4 w-4" />
                                <AlertDescription>Mesa cerrada. Todos los items han sido cobrados.</AlertDescription>
                            </Alert>
                        )}
                        {isQrSession &&
                            cashierState &&
                            !sessionClosed &&
                            Number.parseFloat(cashierState.unpaid_total) > 0 &&
                            cashierState.orders.some((o) => o.status === 'completed') &&
                            (() => {
                                const n = cashierState.orders.filter((o) => o.status === 'completed').length;
                                return (
                                    <Alert variant="destructive">
                                        <AlertCircle className="h-4 w-4" />
                                        <AlertDescription>
                                            {n === 1 ? 'Un pedido fue' : `${n} pedidos fueron`} marcado
                                            {n === 1 ? '' : 's'} como completado{n === 1 ? '' : 's'} sin pago
                                            registrado. <strong>Cobra antes de liberar la mesa.</strong>
                                        </AlertDescription>
                                    </Alert>
                                );
                            })()}

                        {order && (
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                {/* ── Left / main ────────────────────────────── */}
                                <div className="space-y-6 lg:col-span-2">
                                    {/* Guest badges (QR) */}
                                    {isQrSession && sessionDetail && sessionDetail.guests.length > 0 && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">Comensales</h2>
                                            <div className="flex flex-wrap gap-2">
                                                {sessionDetail.guests.map((g) => (
                                                    <GuestBadge
                                                        key={g.id}
                                                        displayName={g.display_name}
                                                        phoneMasked={maskPhone(g.phone)}
                                                        size="sm"
                                                        status={
                                                            sessionDetail.status === 'open' ? 'active' : 'awaiting'
                                                        }
                                                    />
                                                ))}
                                            </div>
                                        </section>
                                    )}

                                    {/* Pending batches — waiter approval */}
                                    {isQrSession && sessionDetail && sessionDetail.pending_batches.length > 0 && (
                                        <section className="space-y-3">
                                            <h2 className="text-foreground text-sm font-semibold">
                                                Tandas pendientes de aprobación
                                            </h2>
                                            <div className="space-y-3">
                                                {sessionDetail.pending_batches.map((batch, i) => (
                                                    <BatchApprovalCard
                                                        key={`${batch.guest_id}-${batch.submitted_at ?? i}`}
                                                        guestName={batch.guest_name}
                                                        guestPhoneMasked={maskPhone(
                                                            guestById[batch.guest_id]?.phone ?? '',
                                                        )}
                                                        submittedAt={batch.submitted_at}
                                                        items={batch.items.map((item) => ({
                                                            id: item.id,
                                                            name: item.name,
                                                            quantity: item.quantity,
                                                            unit_price: item.unit_price,
                                                            notes: item.notes,
                                                            status: item.status,
                                                        }))}
                                                        onApprove={approveBatch}
                                                        onReject={rejectItem}
                                                        disabled={busy || !canUpdateOrders}
                                                    />
                                                ))}
                                            </div>
                                        </section>
                                    )}

                                    {/* Payment breakdown by guest (QR) */}
                                    {isQrSession && cashierState && cashierState.guests.length > 0 && (
                                        <section className="space-y-3">
                                            <h2 className="text-foreground text-sm font-semibold">
                                                {sessionClosed ? 'Items cobrados' : 'Cobro por comensal'}
                                            </h2>
                                            <div className="grid w-full items-start gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(100%,360px),1fr))]">
                                                {cashierState.guests.map((guest) => (
                                                    <GuestItemList
                                                        key={guest.id ?? 'ungrouped'}
                                                        guest={guest}
                                                        selected={selectedByGuest[guest.id ?? 'ungrouped'] ?? {}}
                                                        onToggle={(itemId) =>
                                                            toggleItem(guest.id ?? 'ungrouped', itemId)
                                                        }
                                                        onSelectAllUnpaid={() => selectAllUnpaid(guest)}
                                                        onPay={() => openPayPartial(guest)}
                                                        onRefundItem={(itemId) => {
                                                            const item = guest.items.find((i) => i.id === itemId);
                                                            if (!item) return;
                                                            setRefundItem({
                                                                itemId,
                                                                guestId: guest.id ?? '',
                                                                amount: item.subtotal,
                                                                name: item.name,
                                                            });
                                                            setRefundMethod('cash');
                                                            setRefundReference('');
                                                        }}
                                                        readOnly={sessionClosed || !canUpdateOrders}
                                                    />
                                                ))}
                                            </div>
                                        </section>
                                    )}

                                    {/* Items by status tabs (QR) */}
                                    {isQrSession && sessionDetail && (
                                        <section className="space-y-3">
                                            <h2 className="text-foreground text-sm font-semibold">Items de la mesa</h2>
                                            <Tabs defaultValue="approved" className="w-full">
                                                <TabsList className="flex w-full flex-wrap">
                                                    {ITEM_STATUS_TABS.map((tab) => (
                                                        <TabsTrigger key={tab.key} value={tab.key}>
                                                            {tab.label}
                                                            {sessionDetail.items_by_status[tab.key]?.length ? (
                                                                <Badge variant="secondary" className="ml-1.5">
                                                                    {sessionDetail.items_by_status[tab.key].length}
                                                                </Badge>
                                                            ) : null}
                                                        </TabsTrigger>
                                                    ))}
                                                </TabsList>
                                                {ITEM_STATUS_TABS.map((tab) => (
                                                    <TabsContent
                                                        key={tab.key}
                                                        value={tab.key}
                                                        className="space-y-2 pt-3"
                                                    >
                                                        {(sessionDetail.items_by_status[tab.key] ?? []).length === 0 ? (
                                                            <p className="text-muted-foreground text-center text-sm">
                                                                Sin items.
                                                            </p>
                                                        ) : (
                                                            <ul className="space-y-2">
                                                                {sessionDetail.items_by_status[tab.key].map((item) => (
                                                                    <li key={item.id} className="space-y-1">
                                                                        <OrderItemCard
                                                                            id={item.id}
                                                                            name={item.name}
                                                                            quantity={item.quantity}
                                                                            unitPriceFormatted={formatCurrency(
                                                                                Number.parseFloat(item.unit_price),
                                                                            )}
                                                                            subtotalFormatted={formatCurrency(
                                                                                Number.parseFloat(item.unit_price) *
                                                                                    item.quantity,
                                                                            )}
                                                                            notes={item.notes}
                                                                            status={item.status}
                                                                            cancellationReason={item.cancellation_reason}
                                                                            readOnly
                                                                        />
                                                                        {item.status === 'approved' && !sessionClosed && canUpdateOrders && (
                                                                            <CancelApprovedInline
                                                                                onConfirm={(reason) =>
                                                                                    rejectItem(item.id, reason || undefined)
                                                                                }
                                                                                disabled={busy}
                                                                            />
                                                                        )}
                                                                        {(item.status === 'in_kitchen' ||
                                                                            item.status === 'ready') && (
                                                                            <CancelInKitchenInline
                                                                                onConfirm={(reason) =>
                                                                                    cancelInKitchen(item.id, reason)
                                                                                }
                                                                                disabled={busy}
                                                                            />
                                                                        )}
                                                                        {item.guest_id && guestById[item.guest_id] && (
                                                                            <p className="text-muted-foreground pl-3 text-[11px]">
                                                                                {guestById[item.guest_id].display_name}
                                                                            </p>
                                                                        )}
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        )}
                                                    </TabsContent>
                                                ))}
                                            </Tabs>
                                        </section>
                                    )}

                                    {/* Flat items list (non-QR) */}
                                    {!isQrSession && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">Items</h2>
                                            {(order.line_items ?? []).length > 0 ? (
                                                <ul className="border-border bg-card divide-y rounded-2xl border">
                                                    {(order.line_items ?? []).map((item, idx) => (
                                                        <li
                                                            key={item.id ?? idx}
                                                            className="flex items-start justify-between gap-3 px-4 py-2 text-sm"
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                <p className="text-foreground">
                                                                    <span className="font-medium tabular-nums">
                                                                        {item.quantity}×
                                                                    </span>{' '}
                                                                    {item.name}
                                                                </p>
                                                                {item.notes && (
                                                                    <p className="text-muted-foreground text-xs italic">
                                                                        {item.notes}
                                                                    </p>
                                                                )}
                                                                {item.guest_label && (
                                                                    <p className="text-muted-foreground text-xs">
                                                                        {item.guest_label}
                                                                    </p>
                                                                )}
                                                            </div>
                                                            <span className="text-foreground tabular-nums">
                                                                {formatCurrency(item.unit_price * item.quantity)}
                                                            </span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            ) : order.items.length > 0 ? (
                                                <ul className="border-border bg-card divide-y rounded-2xl border">
                                                    {order.items.map((item) => (
                                                        <li
                                                            key={item.id}
                                                            className="flex items-start justify-between gap-3 px-4 py-2 text-sm"
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                <p className="text-foreground">
                                                                    <span className="font-medium tabular-nums">
                                                                        {item.quantity}×
                                                                    </span>{' '}
                                                                    {item.name}
                                                                </p>
                                                                {item.notes && (
                                                                    <p className="text-muted-foreground text-xs italic">
                                                                        {item.notes}
                                                                    </p>
                                                                )}
                                                            </div>
                                                            <span className="text-foreground tabular-nums">
                                                                {formatCurrency(item.price * item.quantity)}
                                                            </span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            ) : (
                                                <p className="text-muted-foreground text-sm">Sin items.</p>
                                            )}
                                        </section>
                                    )}

                                    {/* Receipts (QR) */}
                                    {isQrSession && cashierState && cashierState.receipts.length > 0 && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">
                                                Comprobantes emitidos
                                            </h2>
                                            <div className="border-border bg-card divide-y rounded-2xl border">
                                                {cashierState.receipts.map((r) => (
                                                    <div
                                                        key={r.id}
                                                        className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm"
                                                    >
                                                        <div className="flex items-center gap-2">
                                                            <Badge
                                                                variant="secondary"
                                                                className={
                                                                    Number.parseFloat(r.amount) < 0
                                                                        ? 'border-transparent bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]'
                                                                        : 'border-transparent bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe)]'
                                                                }
                                                            >
                                                                {r.payment_method}
                                                            </Badge>
                                                            <span className="tabular-nums">
                                                                {formatCurrency(Number.parseFloat(r.amount))}
                                                            </span>
                                                            {r.reference && (
                                                                <span className="text-muted-foreground text-xs">
                                                                    ref: {r.reference}
                                                                </span>
                                                            )}
                                                        </div>
                                                        <span className="text-muted-foreground text-xs">
                                                            {r.paid_at ? formatDateTimeShort(r.paid_at) : ''}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </section>
                                    )}

                                    {/* Timeline (QR) */}
                                    {isQrSession && timeline && timeline.length > 0 && (
                                        <OrderTimeline events={timeline} />
                                    )}

                                    {/* Closed order summary (QR) */}
                                    {isQrSession && sessionClosed && cashierState && (
                                        <ClosedOrderSummary
                                            state={cashierState}
                                            cashierName={cashierName}
                                            paymentLabels={paymentCatalog.labels}
                                        />
                                    )}

                                    {/* Notes */}
                                    {order.notes && order.notes.length > 0 && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">Notas</h2>
                                            <OrderNotesSection notes={order.notes} />
                                        </section>
                                    )}

                                    {/* Related orders */}
                                    {order.related_orders && order.related_orders.length > 0 && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">
                                                Órdenes relacionadas
                                            </h2>
                                            <ul className="border-border bg-card divide-y rounded-2xl border">
                                                {order.related_orders.map((rel) => (
                                                    <li
                                                        key={rel.id}
                                                        className="flex items-center justify-between gap-2 px-4 py-2 text-sm"
                                                    >
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-muted-foreground font-mono text-xs">
                                                                #{shortOrderCode(rel.id)}
                                                            </span>
                                                            <OrderStatusBadge status={rel.status} />
                                                        </div>
                                                        <span className="text-foreground tabular-nums">
                                                            {formatCurrency(rel.total)}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    )}
                                </div>

                                {/* ── Right / sidebar ────────────────────────── */}
                                <aside className="space-y-6">
                                    {/* Summary card */}
                                    <section className="space-y-2">
                                        <h2 className="text-foreground text-sm font-semibold">Resumen</h2>
                                        <div className="border-border bg-card text-card-foreground space-y-2 rounded-2xl border p-4">
                                            <div className="flex items-center justify-between text-sm">
                                                <span className="text-muted-foreground">Estado</span>
                                                <OrderStatusBadge status={order.status} />
                                            </div>
                                            {order.order_type && (
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="text-muted-foreground">Tipo</span>
                                                    <span className="text-foreground">
                                                        {formatOrderTypeLabel(order.order_type, order.table_number)}
                                                    </span>
                                                </div>
                                            )}
                                            <div className="flex items-center justify-between text-sm">
                                                <span className="text-muted-foreground">Total</span>
                                                <span className="text-foreground font-semibold tabular-nums">
                                                    {formatCurrency(order.total)}
                                                </span>
                                            </div>
                                            {isQrSession && cashierState && (
                                                <>
                                                    {Number.parseFloat(cashierState.unpaid_total) > 0 && (
                                                        <div className="flex items-center justify-between text-sm">
                                                            <span className="text-muted-foreground">Pendiente</span>
                                                            <span className="font-semibold tabular-nums text-[color:var(--color-status-critical)]">
                                                                {formatCurrency(
                                                                    Number.parseFloat(cashierState.unpaid_total),
                                                                )}
                                                            </span>
                                                        </div>
                                                    )}
                                                    {Number.parseFloat(cashierState.paid_total) > 0 && (
                                                        <div className="flex items-center justify-between text-sm">
                                                            <span className="text-muted-foreground">Cobrado</span>
                                                            <span className="font-semibold tabular-nums text-[color:var(--color-status-safe)]">
                                                                {formatCurrency(
                                                                    Number.parseFloat(cashierState.paid_total),
                                                                )}
                                                            </span>
                                                        </div>
                                                    )}
                                                    {Number.parseFloat(cashierState.tip_total) > 0 && (
                                                        <div className="flex items-center justify-between text-sm">
                                                            <span className="text-muted-foreground">Propina</span>
                                                            <span className="text-foreground font-semibold tabular-nums">
                                                                {formatCurrency(
                                                                    Number.parseFloat(cashierState.tip_total),
                                                                )}
                                                            </span>
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                            {order.ordered_at && (
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="text-muted-foreground">Fecha</span>
                                                    <span className="text-foreground text-xs">
                                                        {new Intl.DateTimeFormat('es-CO', {
                                                            dateStyle: 'medium',
                                                            timeStyle: 'short',
                                                            timeZone: 'America/Bogota',
                                                        }).format(new Date(order.ordered_at))}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    </section>

                                    {/* Payment info (non-QR) */}
                                    {!isQrSession && order.payment && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">Pago</h2>
                                            <div className="border-border bg-card space-y-1.5 rounded-2xl border p-4">
                                                <DetailRow
                                                    label="Método"
                                                    value={
                                                        (order.payment.method
                                                            ? paymentCatalog.labels[order.payment.method]
                                                            : null) ??
                                                        order.payment.method ??
                                                        '—'
                                                    }
                                                />
                                                {order.payment.reference && (
                                                    <DetailRow
                                                        label="Referencia"
                                                        value={order.payment.reference}
                                                    />
                                                )}
                                            </div>
                                        </section>
                                    )}

                                    {/* Refund info (non-QR) */}
                                    {!isQrSession && order.refund && (order.refund.total_refunded_all ?? order.refund.total_refunded ?? 0) > 0 && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">Devolución</h2>
                                            <div className="border-border bg-card space-y-1.5 rounded-2xl border p-4">
                                                <DetailRow
                                                    label="Total devuelto"
                                                    value={formatCurrency(order.refund.total_refunded_all ?? order.refund.total_refunded ?? 0)}
                                                />
                                                {(order.refund.remaining_refundable ?? 0) > 0 && (
                                                    <DetailRow
                                                        label="Remanente reembolsable"
                                                        value={formatCurrency(order.refund.remaining_refundable ?? 0)}
                                                    />
                                                )}
                                                {order.refund.reference && (
                                                    <DetailRow label="Referencia" value={order.refund.reference} />
                                                )}
                                            </div>
                                        </section>
                                    )}

                                    {/* Delivery info */}
                                    {order.order_type === 'delivery' && order.delivery_address && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">Domicilio</h2>
                                            <div className="border-border bg-card space-y-1.5 rounded-2xl border p-4 text-sm">
                                                <p className="text-foreground">{order.delivery_address}</p>
                                                {order.client_phone && (
                                                    <p className="text-muted-foreground">{order.client_phone}</p>
                                                )}
                                                {order.delivery && (
                                                    <p className="text-muted-foreground">
                                                        {order.delivery.deliverer
                                                            ? `Repartidor: ${order.delivery.deliverer.name}`
                                                            : 'Sin repartidor asignado'}
                                                    </p>
                                                )}
                                            </div>
                                        </section>
                                    )}

                                    {/* Cancellation requests (QR) */}
                                    {isQrSession &&
                                        sessionDetail &&
                                        sessionDetail.cancellation_requests.filter((c) => c.status === 'pending')
                                            .length > 0 && (
                                            <section className="space-y-2">
                                                <h2 className="text-foreground text-sm font-semibold">
                                                    Cancelaciones pendientes
                                                </h2>
                                                <div className="space-y-2">
                                                    {sessionDetail.cancellation_requests
                                                        .filter((c) => c.status === 'pending')
                                                        .map((cr) => {
                                                            const item = Object.values(
                                                                sessionDetail.items_by_status,
                                                            )
                                                                .flat()
                                                                .find((i) => i.id === cr.order_item_id);
                                                            const guest = guestById[cr.guest_id];
                                                            return (
                                                                <CancellationRequestCard
                                                                    key={cr.id}
                                                                    id={cr.id}
                                                                    guestName={guest?.display_name ?? 'Comensal'}
                                                                    itemName={
                                                                        item?.name ?? `Item #${cr.order_item_id}`
                                                                    }
                                                                    itemQuantity={item?.quantity ?? 1}
                                                                    reason={cr.reason}
                                                                    status={cr.status}
                                                                    onApprove={() =>
                                                                        void resolveCancellation(cr.id, 'approved')
                                                                    }
                                                                    onDeny={() =>
                                                                        void resolveCancellation(cr.id, 'denied')
                                                                    }
                                                                    disabled={busy}
                                                                />
                                                            );
                                                        })}
                                                </div>
                                            </section>
                                        )}

                                    {/* Group notes (QR) */}
                                    {isQrSession && sessionDetail && sessionDetail.group_notes.length > 0 && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">Notas de mesa</h2>
                                            <ul className="space-y-2">
                                                {sessionDetail.group_notes.map((note) => (
                                                    <li
                                                        key={note.id}
                                                        className={`rounded-lg border p-2 text-sm ${
                                                            note.scope === 'kitchen_alert'
                                                                ? 'border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)]'
                                                                : 'border-border bg-muted/40'
                                                        }`}
                                                    >
                                                        <div className="text-muted-foreground mb-0.5 flex items-center gap-1 text-[10px] uppercase">
                                                            {note.scope === 'kitchen_alert' ? (
                                                                <>
                                                                    <ChefHat className="h-3 w-3" /> Cocina
                                                                </>
                                                            ) : (
                                                                'Grupo'
                                                            )}
                                                        </div>
                                                        {note.body}
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    )}
                                </aside>
                            </div>
                        )}
                    </>
                )}
            </div>

            {/* ── Dialogs ─── */}
            {isQrSession && (
                <>
                    <ConfirmDialog
                        open={closeEmptyOpen}
                        title="¿Cerrar mesa sin pago?"
                        message="Solo permite si no hay platos servidos ni en cocina. Útil cuando el grupo se levantó sin pedir."
                        confirmLabel="Cerrar mesa"
                        onConfirm={() => void closeEmpty()}
                        onCancel={() => setCloseEmptyOpen(false)}
                        loading={busy}
                    />

                    <BottomSheetDialog
                        isOpen={waiterNoteOpen}
                        onClose={() => setWaiterNoteOpen(false)}
                        title="Nueva nota"
                        className="max-w-md"
                    >
                        <div className="space-y-3 p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={waiterNoteScope === 'group' ? 'default' : 'outline'}
                                    onClick={() => setWaiterNoteScope('group')}
                                    className="flex-1 sm:flex-none"
                                >
                                    Para la mesa
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={waiterNoteScope === 'kitchen_alert' ? 'default' : 'outline'}
                                    onClick={() => setWaiterNoteScope('kitchen_alert')}
                                    className="flex-1 sm:flex-none"
                                >
                                    Para cocina
                                </Button>
                            </div>
                            <NotesEditor
                                value={waiterNoteBody}
                                onChange={setWaiterNoteBody}
                                label={waiterNoteScope === 'kitchen_alert' ? 'Alerta para cocina' : 'Nota grupal'}
                            />
                            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setWaiterNoteOpen(false)}
                                >
                                    <X className="mr-1 h-3.5 w-3.5" /> Cancelar
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => void submitWaiterNote()}
                                    disabled={busy || waiterNoteBody.trim() === ''}
                                >
                                    <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Guardar
                                </Button>
                            </div>
                        </div>
                    </BottomSheetDialog>

                    <SplitPaymentSheet
                        open={!!paySheet}
                        onClose={() => setPaySheet(null)}
                        title={paySheet?.kind === 'all' ? 'Cobrar toda la mesa' : 'Cobrar comensal'}
                        amount={paySheet?.amount ?? '0.00'}
                        onSubmit={submitPayment}
                        loading={busy}
                    />

                    <ConfirmDialog
                        open={!!refundItem}
                        title={`Devolver "${refundItem?.name ?? ''}"`}
                        message={`Se creará un comprobante de devolución por ${refundItem ? formatCurrency(Number.parseFloat(refundItem.amount)) : ''}. El item quedará marcado como devuelto; la venta original se conserva. Esta acción no es reversible.`}
                        confirmLabel="Devolver"
                        onConfirm={() => void submitRefund()}
                        onCancel={() => setRefundItem(null)}
                        loading={busy}
                    />

                    {refundItem && (
                        <div className="fixed inset-x-0 bottom-0 z-40 mx-auto max-w-md space-y-2 p-4">
                            <div className="bg-card border-border rounded-2xl border p-3 shadow-lg">
                                <p className="text-foreground mb-2 text-xs font-semibold">Detalles del refund</p>
                                <div
                                    className="grid gap-2"
                                    style={{
                                        gridTemplateColumns: `repeat(${paymentCatalog.methods.length}, minmax(0, 1fr))`,
                                    }}
                                >
                                    {paymentCatalog.methods.map((m) => (
                                        <Button
                                            key={m}
                                            type="button"
                                            size="sm"
                                            variant={refundMethod === m ? 'default' : 'outline'}
                                            onClick={() => setRefundMethod(m)}
                                        >
                                            {paymentCatalog.labels[m]}
                                        </Button>
                                    ))}
                                </div>
                                <div className="mt-2">
                                    <Label htmlFor="refund-ref" className="text-xs">
                                        Referencia comprobante de devolución (obligatorio)
                                    </Label>
                                    <Input
                                        id="refund-ref"
                                        value={refundReference}
                                        onChange={(e) => setRefundReference(sanitizePlainText(e.target.value, 120, false, false))}
                                        maxLength={120}
                                        placeholder="Ej: voucher reverso 8732"
                                        className="mt-1"
                                    />
                                </div>
                            </div>
                        </div>
                    )}
                </>
            )}

            {/* Non-QR payment sheet */}
            {!isQrSession && orderAsTableOrder && (
                <TablePaymentSheet
                    paymentState={paymentState}
                    selectedOrder={orderAsTableOrder}
                    companyQrUrl={companyQrUrl}
                    tipParsed={tipParsed}
                    expectedTotal={expectedTotal}
                    cashChange={cashChange}
                    formatCurrency={formatCurrency}
                    setPaymentState={setPaymentState}
                    onClose={closePaymentModal}
                    onSubmit={() => void submitTablePayment()}
                    onLookupDianClient={() => void dianLookupClient()}
                />
            )}

            {/* Cancel order */}
            <ConfirmDialog
                open={cancelOpen}
                title="¿Cancelar la orden?"
                message="Esta acción no es reversible. La orden quedará cancelada."
                confirmLabel="Cancelar orden"
                onConfirm={() => void cancelOrder()}
                onCancel={() => setCancelOpen(false)}
                loading={busy}
            />

            {/* Refund order modal */}
            {order && refundModalOpen && (
                <RefundOrderModal
                    order={order}
                    onClose={() => setRefundModalOpen(false)}
                    onConfirmed={() => { setRefundModalOpen(false); void fetchAll(); }}
                />
            )}

            {/* Assign / reassign courier */}
            <AssignCourierModal
                orderId={orderId}
                isOpen={assignCourierOpen}
                onClose={() => setAssignCourierOpen(false)}
                onAssign={handleAssignCourier}
                couriers={couriers}
                loading={couriersLoading}
            />

            {/* Assign table to QR session */}
            <BottomSheetDialog
                isOpen={assignTableOpen}
                onClose={() => { setAssignTableOpen(false); setAssignTableError(null); }}
                title="Asignar mesa"
            >
                <div className="space-y-4 pt-2">
                    {availableTables.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No hay mesas disponibles en este momento.</p>
                    ) : (
                        <Select value={selectedTableId} onValueChange={setSelectedTableId}>
                            <SelectTrigger>
                                <SelectValue placeholder="Selecciona una mesa" />
                            </SelectTrigger>
                            <SelectContent>
                                {availableTables.map((t) => (
                                    <SelectItem key={t.id} value={t.id}>
                                        Mesa {t.number} — {t.capacity} personas
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    {assignTableError && (
                        <Alert variant="destructive">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>{assignTableError}</AlertDescription>
                        </Alert>
                    )}
                    <Button
                        onClick={() => void submitAssignTable()}
                        disabled={!selectedTableId || assignTableBusy || availableTables.length === 0}
                        className="w-full"
                    >
                        {assignTableBusy ? 'Asignando...' : 'Asignar mesa'}
                    </Button>
                </div>
            </BottomSheetDialog>
        </PageShell>
    );
}

// ── Sub-components ────────────────────────────────────────────────────────────

function CancelApprovedInline({
    onConfirm,
    disabled,
}: {
    onConfirm: (reason: string) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');

    if (!open) {
        return (
            <Button
                type="button"
                size="sm"
                variant="ghost"
                className="text-destructive hover:text-destructive h-7 px-2 text-xs"
                onClick={() => setOpen(true)}
                disabled={disabled}
            >
                Cancelar ítem
            </Button>
        );
    }

    return (
        <div className="bg-muted/40 space-y-2 rounded-lg p-2">
            <NotesEditor
                value={reason}
                onChange={setReason}
                label="Motivo (opcional)"
                placeholder="Ej: cliente cambió de opinión"
            />
            <div className="flex items-center justify-end gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        setOpen(false);
                        setReason('');
                    }}
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    onClick={() => {
                        onConfirm(reason);
                        setOpen(false);
                        setReason('');
                    }}
                    disabled={disabled}
                >
                    Confirmar
                </Button>
            </div>
        </div>
    );
}

function CancelInKitchenInline({
    onConfirm,
    disabled,
}: {
    onConfirm: (reason: string) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');

    if (!open) {
        return (
            <Button
                type="button"
                size="sm"
                variant="ghost"
                className="text-destructive hover:text-destructive h-7 px-2 text-xs"
                onClick={() => setOpen(true)}
                disabled={disabled}
            >
                Cancelar este plato
            </Button>
        );
    }

    return (
        <div className="bg-muted/40 space-y-2 rounded-lg p-2">
            <NotesEditor
                value={reason}
                onChange={setReason}
                label="Motivo (obligatorio)"
                placeholder="Ej: se acabó el insumo, cliente cambió de opinión"
            />
            <div className="flex items-center justify-end gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        setOpen(false);
                        setReason('');
                    }}
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    onClick={() => {
                        onConfirm(reason);
                        setOpen(false);
                        setReason('');
                    }}
                    disabled={disabled || reason.trim() === ''}
                >
                    Confirmar
                </Button>
            </div>
        </div>
    );
}

function ClosedOrderSummary({
    state,
    cashierName,
    paymentLabels,
}: {
    state: CashierState;
    cashierName: string;
    paymentLabels: Record<string, string>;
}) {
    const totalItems = state.guests.reduce((acc, g) => acc + g.items.length, 0);
    const totalFact = Number.parseFloat(state.order?.total ?? '0');
    const paid = Number.parseFloat(state.paid_total);
    const tip = Number.parseFloat(state.tip_total);

    const byMethod = state.receipts.reduce<Record<string, number>>((acc, r) => {
        const amt = Number.parseFloat(r.amount);
        if (amt > 0) acc[r.payment_method] = (acc[r.payment_method] ?? 0) + amt;
        return acc;
    }, {});
    const refundTotal = state.receipts.reduce((acc, r) => {
        const amt = Number.parseFloat(r.amount);
        return amt < 0 ? acc + Math.abs(amt) : acc;
    }, 0);

    const orderedAt = state.orders[0]?.ordered_at ?? null;
    const closedAt = state.receipts.reduce<string | null>((latest, r) => {
        if (!r.paid_at) return latest;
        if (!latest) return r.paid_at;
        return r.paid_at > latest ? r.paid_at : latest;
    }, null);

    const fmt = (d: string) =>
        formatDateTime(d, { dateStyle: 'medium', timeStyle: 'short' });

    return (
        <section className="border-border bg-card space-y-4 rounded-2xl border p-4 shadow-sm">
            <h2 className="text-foreground text-sm font-semibold">Resumen de cobro</h2>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <KpiCell label="Total facturado" value={formatCurrency(totalFact)} />
                <KpiCell label="Total cobrado" value={formatCurrency(paid)} />
                {tip > 0 && <KpiCell label="Propina" value={formatCurrency(tip)} />}
                <KpiCell label="Items" value={totalItems} />
            </div>
            {Object.keys(byMethod).length > 0 && (
                <div className="border-border border-t pt-3">
                    <p className="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
                        Desglose por método
                    </p>
                    <div className="grid grid-cols-2 gap-x-6 gap-y-1.5 sm:grid-cols-3">
                        {Object.entries(byMethod).map(([method, amount]) => (
                            <DetailRow
                                key={method}
                                label={paymentLabels[method] ?? method}
                                value={formatCurrency(amount)}
                                valueClassName="text-sm font-semibold tabular-nums"
                            />
                        ))}
                        {refundTotal > 0 && (
                            <DetailRow
                                label="Devoluciones"
                                value={`-${formatCurrency(refundTotal)}`}
                                valueClassName="text-sm font-semibold tabular-nums text-[color:var(--color-status-critical)]"
                            />
                        )}
                    </div>
                </div>
            )}
            <div className="border-border grid grid-cols-2 gap-x-6 gap-y-1.5 border-t pt-3 sm:grid-cols-3">
                {orderedAt && <DetailRow label="Pedido el" value={fmt(orderedAt)} valueClassName="text-sm" />}
                {closedAt && <DetailRow label="Cerrado el" value={fmt(closedAt)} valueClassName="text-sm" />}
                <DetailRow label="Cajero" value={cashierName} valueClassName="text-sm font-medium" />
                <DetailRow
                    label="Comprobantes"
                    value={state.receipts.length}
                    valueClassName="text-sm tabular-nums"
                />
            </div>
        </section>
    );
}

const ACTION_META: Record<string, { dot: string; text: string }> = {
    'session.opened': {
        dot: 'bg-[color:var(--color-status-info)]',
        text: 'text-[color:var(--color-status-info)]',
    },
    'session.closed': {
        dot: 'bg-[color:var(--color-status-safe)]',
        text: 'text-[color:var(--color-status-safe)]',
    },
    'order.created': { dot: 'bg-foreground', text: 'text-foreground' },
    'kds.in_kitchen': {
        dot: 'bg-[color:var(--color-status-warning)]',
        text: 'text-[color:var(--color-status-warning)]',
    },
    'kds.ready': {
        dot: 'bg-[color:var(--color-status-safe)]',
        text: 'text-[color:var(--color-status-safe)]',
    },
    'payment.split': {
        dot: 'bg-[color:var(--color-status-info)]',
        text: 'text-[color:var(--color-status-info)]',
    },
    'order.status.completed': {
        dot: 'bg-[color:var(--color-status-safe)]',
        text: 'text-[color:var(--color-status-safe)]',
    },
    'order.status.cancelled': {
        dot: 'bg-[color:var(--color-status-critical)]',
        text: 'text-[color:var(--color-status-critical)]',
    },
    'order.status.refunded': {
        dot: 'bg-[color:var(--color-status-critical)]',
        text: 'text-[color:var(--color-status-critical)]',
    },
};

function OrderTimeline({ events }: { events: TimelineEvent[] }) {
    const fmtTime = (iso: string) =>
        new Date(iso).toLocaleTimeString('es-CO', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            timeZone: 'America/Bogota',
        });

    const openedEvent = events.find((e) => e.action === 'session.opened');
    const closedEvent = events.find((e) => e.action === 'session.closed');
    const firstOrderEvent = events
        .filter((e) => e.action === 'order.created')
        .sort((a, b) => a.at.localeCompare(b.at))[0];
    const firstReadyEvent = events
        .filter((e) => e.action === 'kds.ready')
        .sort((a, b) => a.at.localeCompare(b.at))[0];
    const firstPaymentEvent = events
        .filter((e) => e.action === 'payment.split')
        .sort((a, b) => a.at.localeCompare(b.at))[0];

    const totalStart = firstOrderEvent ?? openedEvent;
    const totalSeconds = totalStart
        ? Math.floor(
              ((closedEvent ? new Date(closedEvent.at) : new Date()).getTime() -
                  new Date(totalStart.at).getTime()) /
                  1000,
          )
        : null;

    const waitSeconds =
        firstOrderEvent && firstReadyEvent
            ? Math.floor(
                  (new Date(firstReadyEvent.at).getTime() - new Date(firstOrderEvent.at).getTime()) / 1000,
              )
            : null;

    const consumeEnd = firstPaymentEvent ?? closedEvent;
    const consumeSeconds =
        firstReadyEvent && consumeEnd
            ? Math.floor(
                  (new Date(consumeEnd.at).getTime() - new Date(firstReadyEvent.at).getTime()) / 1000,
              )
            : null;

    return (
        <section className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-foreground flex items-center gap-1.5 text-sm font-semibold">
                    <Clock className="h-4 w-4" />
                    Historial de la mesa
                </h2>
                <div className="flex flex-wrap items-center gap-1.5">
                    {waitSeconds !== null && waitSeconds > 0 && (
                        <span
                            className="text-muted-foreground bg-muted rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums"
                            title="Tiempo desde que ordenó hasta que llegó el primer plato"
                        >
                            esperó {formatDuration(waitSeconds)}
                        </span>
                    )}
                    {consumeSeconds !== null && consumeSeconds > 0 && (
                        <span
                            className="text-muted-foreground bg-muted rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums"
                            title="Tiempo desde que llegó el pedido hasta el primer pago"
                        >
                            comió {formatDuration(consumeSeconds)}
                        </span>
                    )}
                    {totalSeconds !== null && (
                        <span className="text-muted-foreground bg-muted rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums">
                            {closedEvent ? '' : '⏱ '}
                            {formatDuration(totalSeconds)} en mesa
                        </span>
                    )}
                </div>
            </div>
            <div className="border-border bg-card rounded-2xl border px-4 py-3">
                <ol className="relative space-y-0">
                    {events.map((ev, i) => {
                        const meta = ACTION_META[ev.action] ?? {
                            dot: 'bg-muted-foreground',
                            text: 'text-muted-foreground',
                        };
                        const isLast = i === events.length - 1;
                        return (
                            <li key={`${ev.action}-${ev.at}-${i}`} className="relative flex gap-3 pb-4 last:pb-0">
                                {!isLast && (
                                    <div className="border-border absolute top-3 left-[7px] h-full border-l border-dashed" />
                                )}
                                <div
                                    className={`relative mt-1 h-3.5 w-3.5 shrink-0 rounded-full ${meta.dot} ring-background ring-2`}
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <span className={`text-sm font-medium ${meta.text}`}>{ev.label}</span>
                                        {ev.duration_seconds !== null &&
                                            ev.duration_seconds > 0 &&
                                            ev.duration_seconds <= 86400 && (
                                                <span className="text-muted-foreground bg-muted rounded px-1.5 py-0.5 text-[11px] tabular-nums">
                                                    +{formatDuration(ev.duration_seconds)}
                                                </span>
                                            )}
                                    </div>
                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {fmtTime(ev.at)}
                                        </span>
                                        {ev.detail && (
                                            <span className="text-muted-foreground text-xs">{ev.detail}</span>
                                        )}
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            </div>
        </section>
    );
}

// ── Pure helpers ──────────────────────────────────────────────────────────────

function buildPageDescription(
    order: KanbanOrder | null,
    cashierState: CashierState | null,
    cashierName: string,
): string {
    if (!order) return 'Cargando…';
    if (cashierState) {
        if (!cashierState.order) return `Sin pedidos · ${cashierName}`;
        const unpaid = Number.parseFloat(cashierState.unpaid_total);
        const paid = Number.parseFloat(cashierState.paid_total);
        const tip = Number.parseFloat(cashierState.tip_total);
        const parts: string[] = [];
        if (unpaid > 0) parts.push(`Pendiente: ${formatCurrency(unpaid)}`);
        if (paid > 0) parts.push(`Cobrado: ${formatCurrency(paid)}`);
        if (tip > 0) parts.push(`Propina: ${formatCurrency(tip)}`);
        parts.push(cashierName);
        return parts.join(' · ');
    }
    const parts = [`#${shortOrderCode(order.id)}`];
    if (order.ordered_at) {
        parts.push(
            new Intl.DateTimeFormat('es-CO', {
                dateStyle: 'short',
                timeStyle: 'short',
                timeZone: 'America/Bogota',
            }).format(new Date(order.ordered_at)),
        );
    }
    return parts.join(' · ');
}


function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    if (seconds < 3600) return s > 0 ? `${m}m ${s}s` : `${m}m`;
    const h = Math.floor(m / 60);
    const rm = m % 60;
    return rm > 0 ? `${h}h ${rm}m` : `${h}h`;
}


function GatedButton({ allowed, children, disabled, className, ...props }: ComponentProps<typeof Button> & { allowed: boolean }) {
    if (allowed) return <Button {...props} className={className} disabled={disabled}>{children}</Button>;
    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className={`inline-flex ${className ?? ''}`} tabIndex={0}>
                        <Button {...props} className={className} disabled>
                            {children}
                        </Button>
                    </span>
                </TooltipTrigger>
                <TooltipContent side="bottom">Permisos insuficientes</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
