import { AssignDelivererModal } from '@/components/deliveries/assign-deliverer-modal';
import { DaySalesSummary } from '@/components/deliveries/day-sales-summary';
import { DaySalesTable } from '@/components/deliveries/day-sales-table';
import { ReassignModal } from '@/components/deliveries/reassign-modal';
import { RefundOrderModal } from '@/components/deliveries/refund-order-modal';
import { OrderDetailModal } from '@/components/orders/order-detail-modal';
import { PageShell } from '@/components/page-shell';
import ExportPdfButton from '@/components/reports/export-pdf-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DaySalesSkeleton } from '@/components/ui/day-sales-skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { useToast } from '@/components/ui/toast';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useDaySales, todayBogota, type DaySalesParams, type DaySalesSummary as DaySalesSummaryData } from '@/hooks/use-day-sales';
import { useDaySalesActions } from '@/hooks/use-day-sales-actions';
import { useDaySalesSort } from '@/hooks/use-day-sales-sort';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';

import { AlertCircle, Inbox, RefreshCw, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

export default function DaySalesIndex() {
    const token = useToken();
    const { showToast } = useToast();
    const formatCurrency = useCurrencyFormatter();
    const orderStatuses = useOrderStatuses();

    // Draft: lo que el usuario está editando en los inputs
    const today = todayBogota();
    const [draftDateFrom, setDraftDateFrom] = useState(today);
    const [draftDateTo, setDraftDateTo] = useState(today);
    const [draftSearch, setDraftSearch] = useState('');
    const [draftMinAmount, setDraftMinAmount] = useState('');
    const [draftMaxAmount, setDraftMaxAmount] = useState('');

    // Applied: lo que se envía al backend (solo cambia con "Buscar")
    const [applied, setApplied] = useState<DaySalesParams>({
        dateFrom: today,
        dateTo: today,
        search: '',
        minAmount: '',
        maxAmount: '',
    });

    // Estado: filtro client-side, aplica inmediato (no necesita backend)
    const [statusFilters, setStatusFilters] = useState<string[]>([]);

    const { orders, summary, period, loading, error, refresh, lastUpdated } = useDaySales(token, applied);

    const [cancelOrderId, setCancelOrderId] = useState<string | null>(null);
    const [cancelling, setCancelling] = useState(false);

    function applyFilters() {
        setApplied({
            dateFrom: draftDateFrom,
            dateTo: draftDateTo,
            search: draftSearch,
            minAmount: draftMinAmount,
            maxAmount: draftMaxAmount,
        });
    }

    const filteredOrders = statusFilters.length > 0 ? orders.filter((o) => statusFilters.includes(o.status)) : orders;

    // BUG-L03: cuando hay filtro de estado, derivar KPIs de filteredOrders para
    // que las tarjetas sean consistentes con la tabla. total_refunded es
    // aproximado (usa orders.total, no receipts) pero es el mejor dato disponible.
    const displaySummary = useMemo((): DaySalesSummaryData | null => {
        if (statusFilters.length === 0 || !summary) return summary;
        const gross = filteredOrders.filter((o) => o.status === 'completed').reduce((s, o) => s + Number(o.total), 0);
        const refunds = filteredOrders.filter((o) => o.status === 'refunded').reduce((s, o) => s + Number(o.total), 0);
        return {
            total_orders: filteredOrders.length,
            completed: filteredOrders.filter((o) => o.status === 'completed').length,
            cancelled: filteredOrders.filter((o) => o.status === 'cancelled').length,
            refunded: filteredOrders.filter((o) => o.status === 'refunded').length,
            abandoned: filteredOrders.filter((o) => o.status === 'abandoned').length,
            total_revenue: gross,
            total_refunded: refunds,
            net_revenue: gross - refunds,
        };
    }, [statusFilters, filteredOrders, summary]);

    const { sortColumn, sortDirection, toggleSort, sortedOrders } = useDaySalesSort(filteredOrders, orderStatuses);

    const {
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
    } = useDaySalesActions({ orders, refresh });

    async function confirmCancel() {
        if (cancelOrderId === null) return;
        setCancelling(true);
        try {
            const res = await apiFetch(`/api/v1/orders/${cancelOrderId}/cancel`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({}),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                showToast('error', (json as { message?: string }).message ?? 'No se pudo cancelar la orden.');
                return;
            }
            setCancelOrderId(null);
            setOrderDetail(null);
            void refresh();
        } catch {
            showToast('error', 'Error de conexión al cancelar.');
        } finally {
            setCancelling(false);
        }
    }

    return (
        <PageShell title="Ventas del día">
            <div className="p-4 sm:p-6">
                {loading && orders.length === 0 ? (
                    <DaySalesSkeleton />
                ) : (
                    <div className="space-y-6">
                        <PageHeader
                            eyebrow="Órdenes"
                            title="Ventas del día"
                            description={
                                period?.from
                                    ? (() => {
                                          const fmtShort = (d: string) =>
                                              new Intl.DateTimeFormat('es-CO', {
                                                  day: 'numeric',
                                                  month: 'long',
                                                  year: 'numeric',
                                                  timeZone: 'America/Bogota',
                                              }).format(new Date(d + 'T12:00:00'));
                                          const formatted =
                                              period.from === period.to
                                                  ? new Intl.DateTimeFormat('es-CO', {
                                                        weekday: 'long',
                                                        day: 'numeric',
                                                        month: 'long',
                                                        year: 'numeric',
                                                        timeZone: 'America/Bogota',
                                                    }).format(new Date(period.from + 'T12:00:00'))
                                                  : `${fmtShort(period.from)} – ${fmtShort(period.to)}`;
                                          const stamp = lastUpdated
                                              ? ` · Actualizado a las ${lastUpdated.toLocaleTimeString('es-CO', {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                    timeZone: 'America/Bogota',
                                                })}`
                                              : '';
                                          return `${formatted}${stamp}`;
                                      })()
                                    : 'Resumen de operación del día.'
                            }
                            actions={
                                <>
                                    <ExportPdfButton
                                        endpoint="/api/v1/exports/orders/pdf"
                                        filters={{
                                            period: 'custom',
                                            date_from: applied.dateFrom,
                                            date_to: applied.dateTo,
                                        }}
                                        filename={`ventas_${new Date().toISOString().slice(0, 10)}.pdf`}
                                        disabled={orders.length === 0}
                                    />
                                    <Button variant="outline" size="sm" onClick={() => void refresh()} disabled={loading}>
                                        <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                                        Actualizar
                                    </Button>
                                </>
                            }
                        />

                        <DaySalesSummary summary={displaySummary} formatCurrency={formatCurrency} />

                        {/* Filtros */}
                        <div className="flex flex-col gap-3">
                            {/* Fila 1: fechas + búsqueda */}
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="date-from" className="text-xs">Desde</Label>
                                    <Input
                                        id="date-from"
                                        type="date"
                                        value={draftDateFrom}
                                        onChange={(e) => setDraftDateFrom(e.target.value)}
                                        className="h-9 w-[155px]"
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="date-to" className="text-xs">Hasta</Label>
                                    <Input
                                        id="date-to"
                                        type="date"
                                        value={draftDateTo}
                                        min={draftDateFrom}
                                        onChange={(e) => setDraftDateTo(e.target.value)}
                                        className="h-9 w-[155px]"
                                    />
                                </div>
                                <div className="flex flex-col gap-1 flex-1 min-w-[200px]">
                                    <Label htmlFor="search-filter" className="text-xs">Buscar cliente</Label>
                                    <Input
                                        id="search-filter"
                                        type="search"
                                        placeholder="Nombre, teléfono o documento"
                                        value={draftSearch}
                                        onChange={(e) => setDraftSearch(e.target.value)}
                                        onKeyDown={(e) => { if (e.key === 'Enter') applyFilters(); }}
                                        className="h-9"
                                    />
                                </div>
                            </div>
                            {/* Fila 2: estado + montos + buscar */}
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="flex flex-col gap-1">
                                    <Label className="text-xs">Estado</Label>
                                    <Combobox
                                        multiple
                                        floating
                                        id="status-filter"
                                        value={statusFilters}
                                        onChange={setStatusFilters}
                                        options={orderStatuses.all.map((s) => ({ value: s, label: orderStatuses.labels[s] ?? s }))}
                                        placeholder="Todos los estados"
                                        clearable
                                        className="sm:min-w-[200px]"
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="min-amount" className="text-xs">Monto mín.</Label>
                                    <Input
                                        id="min-amount"
                                        type="number"
                                        min={0}
                                        placeholder="0"
                                        value={draftMinAmount}
                                        onChange={(e) => setDraftMinAmount(e.target.value)}
                                        onKeyDown={(e) => { if (e.key === 'Enter') applyFilters(); }}
                                        className="h-9 w-[120px]"
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="max-amount" className="text-xs">Monto máx.</Label>
                                    <Input
                                        id="max-amount"
                                        type="number"
                                        min={0}
                                        placeholder="∞"
                                        value={draftMaxAmount}
                                        onChange={(e) => setDraftMaxAmount(e.target.value)}
                                        onKeyDown={(e) => { if (e.key === 'Enter') applyFilters(); }}
                                        className="h-9 w-[120px]"
                                    />
                                </div>
                                <div className="flex flex-col justify-end">
                                    <Button size="sm" onClick={applyFilters} disabled={loading} className="h-9">
                                        <Search className="mr-1.5 h-3.5 w-3.5" />
                                        Buscar
                                    </Button>
                                </div>
                            </div>
                        </div>

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {/* Tabla / Cards */}
                        {filteredOrders.length === 0 ? (
                            <EmptyState
                                icon={Inbox}
                                title={orders.length === 0 ? 'Sin pedidos en este período' : 'Sin coincidencias'}
                                description={
                                    orders.length === 0
                                        ? 'Cuando entren pedidos al sistema aparecerán acá.'
                                        : 'Ningún pedido coincide con los filtros aplicados. Cambiá el filtro para ver otros.'
                                }
                            />
                        ) : (
                            <DaySalesTable
                                sortedOrders={sortedOrders}
                                orders={filteredOrders}
                                sortColumn={sortColumn}
                                sortDirection={sortDirection}
                                onToggleSort={toggleSort}
                                formatCurrency={formatCurrency}
                                onOpenOrder={(orderId) => void openOrderDetail(orderId)}
                            />
                        )}
                    </div>
                )}
            </div>

            {assignOrderId !== null && (
                <AssignDelivererModal
                    orderId={assignOrderId}
                    deliverers={deliverers}
                    loadingDeliverers={loadingDeliverers}
                    onAssign={handleAssign}
                    onClose={() => setAssignOrderId(null)}
                    submitting={submitting}
                />
            )}

            {reassignContext !== null && (
                <ReassignModal
                    orderId={reassignContext.orderId}
                    currentUserId={reassignContext.currentUserId}
                    isOpen={true}
                    couriers={deliverers}
                    loading={loadingDeliverers}
                    onConfirm={handleReassign}
                    onClose={() => setReassignContext(null)}
                />
            )}

            <OrderDetailModal
                order={orderDetail}
                isOpen={orderDetail !== null}
                onClose={() => setOrderDetail(null)}
                payment={orderDetail?.payment ?? null}
                refund={orderDetail?.refund ?? null}
                onAssignCourier={(orderId) => void openAssign(orderId)}
                onReassignCourier={(orderId, deliveryId) => void openReassign(orderId, deliveryId)}
                onCancelOrder={(orderId) => setCancelOrderId(orderId)}
                onRefundOrder={(orderId) => setRefundOrderId(orderId)}
            />

            {refundOrderId !== null && orderDetail && (
                <RefundOrderModal
                    order={orderDetail}
                    onClose={() => setRefundOrderId(null)}
                    onConfirmed={() => {
                        setRefundOrderId(null);
                        setOrderDetail(null);
                        void refresh();
                    }}
                />
            )}

            <ConfirmDialog
                open={cancelOrderId !== null}
                title="¿Cancelar esta orden?"
                message="La orden quedará en estado cancelada. Esta acción no se puede deshacer."
                confirmLabel="Cancelar orden"
                cancelLabel="Volver"
                loading={cancelling}
                onConfirm={() => void confirmCancel()}
                onCancel={() => setCancelOrderId(null)}
            />
        </PageShell>
    );
}
