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
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DaySalesSkeleton } from '@/components/ui/day-sales-skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useToast } from '@/components/ui/toast';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useDaySales } from '@/hooks/use-day-sales';
import { useDaySalesActions } from '@/hooks/use-day-sales-actions';
import { useDaySalesSort } from '@/hooks/use-day-sales-sort';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';

import { AlertCircle, Inbox, RefreshCw } from 'lucide-react';
import { useState } from 'react';

export default function DaySalesIndex() {
    const token = useToken();
    const { showToast } = useToast();
    const formatCurrency = useCurrencyFormatter();
    const orderStatuses = useOrderStatuses();
    const { orders, summary, period, loading, error, statusFilter, setStatusFilter, refresh, lastUpdated } = useDaySales(token);
    const [cancelOrderId, setCancelOrderId] = useState<string | null>(null);
    const [cancelling, setCancelling] = useState(false);

    const { sortColumn, sortDirection, toggleSort, sortedOrders } = useDaySalesSort(orders, orderStatuses);

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
                                          const formatted = new Intl.DateTimeFormat('es-CO', {
                                              weekday: 'long',
                                              day: 'numeric',
                                              month: 'long',
                                              year: 'numeric',
                                              timeZone: 'America/Bogota',
                                          }).format(new Date(period.from + 'T12:00:00'));
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
                                            period: 'daily',
                                            status: statusFilter === 'all' ? undefined : statusFilter,
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

                        <DaySalesSummary summary={summary} formatCurrency={formatCurrency} />

                        {/* Filtros de status */}
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                            <Label htmlFor="status-filter" className="text-xs">
                                Filtrar por estado
                            </Label>
                            <Select value={statusFilter} onValueChange={setStatusFilter}>
                                <SelectTrigger id="status-filter" className="h-9 w-full sm:w-auto sm:min-w-[200px]">
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
                                    {orderStatuses.all.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {orderStatuses.labels[s]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {/* Tabla / Cards */}
                        {orders.length === 0 ? (
                            <EmptyState
                                icon={Inbox}
                                title={statusFilter === 'all' ? 'Sin pedidos hoy' : 'Sin coincidencias'}
                                description={
                                    statusFilter === 'all'
                                        ? 'Cuando entren pedidos al sistema aparecerán acá.'
                                        : `No hay pedidos con estado "${orderStatuses.labels[statusFilter as never] ?? statusFilter}". Cambiá el filtro para ver otros.`
                                }
                            />
                        ) : (
                            <DaySalesTable
                                sortedOrders={sortedOrders}
                                orders={orders}
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

            {/* Modal de asignación de repartidor */}
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

            {/* Modal de reasignación */}
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

            {/* Modal de detalle de orden con acciones contextuales (cancel/refund/asignar) */}
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
