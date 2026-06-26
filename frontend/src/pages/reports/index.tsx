import { PageShell } from '@/components/page-shell';
import { BranchComparisonTable, type BranchBreakdownRow } from '@/components/reports/branch-comparison-table';
import BranchFilterTabs from '@/components/reports/branch-filter-tabs';
import CashDrawerCard from '@/components/reports/cash-drawer-card';
import CashSessionsCard from '@/components/reports/cash-sessions-card';
import SmsSentCard from '@/components/reports/sms-sent-card';
import ExportPdfButton from '@/components/reports/export-pdf-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { DistributionBars, type DistributionItem } from '@/components/ui/distribution-bars';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { PeriodTabs } from '@/components/ui/period-tabs';
import { ReportsTableSkeleton } from '@/components/ui/reports-table-skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { StatTile } from '@/components/ui/stat-tile';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import type { KanbanOrder } from '@/hooks/use-orders';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { shiftDate, todayInBogota } from '@/lib/datetime';
import { statusBadgeClass, statusLabel } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import { type Order, type OrderStatus, type ReportSummary } from '@/types';

import { OrderDetailModal } from '@/components/orders/order-detail-modal';
import { Link2, RefreshCw } from 'lucide-react';
import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';

interface Pagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

/**
 * Order enriquecido con metadata operativa para visualizar relación entre
 * tandas de la misma mesa. Los campos extra (`table_session_id`, etc.)
 * vienen del Eloquent serializado del backend pero no estaban en el tipo
 * compartido `Order`.
 */
interface ReportOrder extends Order {
    table_session_id?: string | null;
    table_number?: string | null;
    order_type?: string | null;
}

/**
 * Paleta de colores estables para distinguir sesiones de mesa en la tabla
 * de órdenes. Index estable derivado del session_id para que entre
 * refrescos la misma sesión conserve su color (mejor scan visual).
 */
const SESSION_BORDER_PALETTE = [
    'border-l-[color:var(--color-status-info)]',
    'border-l-[color:var(--color-status-warning)]',
    'border-l-[color:var(--color-status-safe)]',
    'border-l-[color:var(--color-status-critical)]',
    'border-l-primary',
] as const;

function sessionBorderClass(sessionId: string): string {
    // Hash sencillo basado en suma de char codes para selección estable de color.
    let h = 0;
    for (let i = 0; i < sessionId.length; i++) h = (h + sessionId.charCodeAt(i)) >>> 0;
    return SESSION_BORDER_PALETTE[h % SESSION_BORDER_PALETTE.length];
}

type Period = 'daily' | 'weekly' | 'monthly' | 'specific_day' | 'custom';
type StatusFilter = 'all' | OrderStatus;

const PERIOD_OPTIONS: ReadonlyArray<{ value: Period; label: string }> = [
    { value: 'daily', label: 'Hoy' },
    { value: 'weekly', label: 'Semana' },
    { value: 'monthly', label: 'Últ. 30 días' },
    { value: 'specific_day', label: 'Día específico' },
    { value: 'custom', label: 'Personalizado' },
];


function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleString('es-CO', {
        timeZone: 'America/Bogota',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function Reports() {
    const activeToken = useToken();
    const orderStatuses = useOrderStatuses();

    const [period, setPeriod] = useState<Period>('daily');
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [specificDay, setSpecificDay] = useState(todayInBogota);
    const [currentPage, setCurrentPage] = useState(1);
    // Multi-sede (#117): default 'active' = sede actual (BranchScope filtra por JWT).
    const [branchFilter, setBranchFilter] = useState<string>('active');

    const [summary, setSummary] = useState<ReportSummary | null>(null);
    const [orders, setOrders] = useState<ReportOrder[]>([]);
    const [pagination, setPagination] = useState<Pagination | null>(null);
    const [periodRange, setPeriodRange] = useState<{ from: string; to: string } | null>(null);
    // Multi-sede (#192): breakdown por sede cuando scope='consolidated'.
    const [perBranch, setPerBranch] = useState<BranchBreakdownRow[]>([]);
    const [reportScope, setReportScope] = useState<'branch' | 'consolidated'>('branch');
    const [loading, setLoading] = useState(false);
    const [fetchError, setFetchError] = useState<string | null>(null);
    const [validationError, setValidationError] = useState<string | null>(null);
    const [selectedOrder, setSelectedOrder] = useState<KanbanOrder | null>(null);

    /**
     * Conteo de órdenes por `table_session_id` en la página actual. Sirve
     * para marcar visualmente cuando dos o más órdenes vienen de la misma
     * sesión de mesa (ej. cliente pidió varias tandas en la misma visita).
     */
    const sessionGroups = useMemo(() => {
        const map = new Map<string, ReportOrder[]>();
        for (const o of orders) {
            if (!o.table_session_id) continue;
            const list = map.get(o.table_session_id) ?? [];
            list.push(o);
            map.set(o.table_session_id, list);
        }
        return map;
    }, [orders]);

    /**
     * Reordena las órdenes para que las que pertenecen a una misma sesión
     * de mesa queden CONSECUTIVAS — eso hace mucho más obvio "estas órdenes
     * están relacionadas". Ordenadas dentro del grupo de más antigua a más
     * reciente (cronológico). Las órdenes sueltas (sin session) mantienen
     * su posición original respetando el orden global del backend.
     *
     * Anclamos cada grupo en la posición de su orden más reciente: así una
     * sesión que pasó hace una hora baja en la tabla; una sesión nueva sube
     * arriba. La cohesión visual de las filas del grupo se mantiene.
     */
    const orderedOrders = useMemo((): ReportOrder[] => {
        const seenSessions = new Set<string>();
        const result: ReportOrder[] = [];
        for (const o of orders) {
            const sid = o.table_session_id;
            if (!sid) {
                result.push(o);
                continue;
            }
            if (seenSessions.has(sid)) continue;
            seenSessions.add(sid);
            const group = sessionGroups.get(sid) ?? [o];
            // Dentro del grupo: cronológico (más antigua primero) para que
            // el cajero/admin vea la secuencia natural de la tanda.
            const sortedGroup = [...group].sort((a, b) => a.ordered_at.localeCompare(b.ordered_at));
            result.push(...sortedGroup);
        }
        return result;
    }, [orders, sessionGroups]);

    /**
     * Al click en una fila, hacemos GET /api/v1/orders/{id} para obtener el
     * payload extendido (items, notes, line_items, payment, refund). El
     * OrderDetailModal ya sabe leer ese shape — solo hay que armar el
     * KanbanOrder mínimo y dejar que el modal complete los campos faltantes.
     */
    const openOrderDetail = useCallback(async (orderId: string) => {
        try {
            const res = await apiFetch(`/api/v1/orders/${orderId}`);
            if (!res.ok) return;
            const json = await res.json();
            setSelectedOrder(json.data as KanbanOrder);
        } catch {
            // Silent — si falla, no abrimos el modal.
        }
    }, []);

    // El modal de detalle dispara este evento cuando el operador clickea
    // una orden hermana. Reusamos la misma fetch + setSelectedOrder para
    // que sea como navegar lateralmente entre órdenes de la misma sesión.
    useEffect(() => {
        const handler = (e: Event) => {
            const detail = (e as CustomEvent<{ orderId: string }>).detail;
            if (detail?.orderId) {
                void openOrderDetail(detail.orderId);
            }
        };
        window.addEventListener('open-order-detail', handler);
        return () => window.removeEventListener('open-order-detail', handler);
    }, [openOrderDetail]);

    const today = todayInBogota();

    // Rango efectivo de fechas para los cards de caja (que usan date_from/date_to, no period).
    const resolvedCashRange = useMemo(() => {
        switch (period) {
            case 'daily': return { from: today, to: today };
            case 'weekly': return { from: shiftDate(today, -6), to: today };
            case 'monthly': return { from: shiftDate(today, -29), to: today };
            case 'specific_day': return { from: specificDay, to: specificDay };
            case 'custom': return dateFrom && dateTo ? { from: dateFrom, to: dateTo } : { from: today, to: today };
        }
    }, [period, specificDay, dateFrom, dateTo, today]);

    const buildQuery = useCallback(
        (page: number) => {
            const params = new URLSearchParams({ status: statusFilter, page: String(page), per_page: '20' });
            if (period === 'specific_day') {
                params.set('period', 'custom');
                params.set('date_from', specificDay);
                params.set('date_to', specificDay);
            } else {
                params.set('period', period);
                if (period === 'custom') {
                    if (dateFrom) params.set('date_from', dateFrom);
                    if (dateTo) params.set('date_to', dateTo);
                }
            }
            if (branchFilter !== 'active') params.set('branch', branchFilter);
            return params.toString();
        },
        [period, dateFrom, dateTo, specificDay, statusFilter, branchFilter],
    );

    const fetchReport = useCallback(
        async (page: number) => {
            if (!activeToken) return;
            if (period === 'custom' && (!dateFrom || !dateTo)) return;
            if (period === 'specific_day' && !specificDay) return;

            setLoading(true);
            setFetchError(null);
            setValidationError(null);

            try {
                const response = await apiFetch(`/api/v1/reports/orders?${buildQuery(page)}`);
                const data = await response.json();

                if (!response.ok) {
                    if (response.status === 422 && data.errors) {
                        const firstError = Object.values(data.errors as Record<string, string[]>)[0]?.[0];
                        setValidationError(firstError ?? 'Parámetros inválidos.');
                    } else {
                        setFetchError(data.message ?? 'Error al cargar el informe.');
                    }
                    return;
                }

                setSummary(data.summary);
                setOrders(data.orders);
                setPagination(data.pagination);
                setPeriodRange(data.period);
                setReportScope(data.scope === 'consolidated' ? 'consolidated' : 'branch');
                setPerBranch(Array.isArray(data.per_branch) ? data.per_branch : []);
            } catch {
                setFetchError('Error de conexión.');
            } finally {
                setLoading(false);
            }
        },
        [activeToken, period, dateFrom, dateTo, buildQuery],
    );

    useEffect(() => {
        setCurrentPage(1);
        fetchReport(1);
    }, [period, statusFilter, dateFrom, dateTo, specificDay, branchFilter, fetchReport]);

    function handlePageChange(page: number) {
        setCurrentPage(page);
        fetchReport(page);
    }

    function handleApplyCustom() {
        setCurrentPage(1);
        fetchReport(1);
    }

    function buildPdfFilters(): Record<string, unknown> {
        const f: Record<string, unknown> = {};
        if (periodRange) {
            f.date_from = periodRange.from;
            f.date_to = periodRange.to;
        } else if (period === 'specific_day') {
            f.date_from = specificDay;
            f.date_to = specificDay;
        } else if (period === 'custom') {
            if (dateFrom) f.date_from = dateFrom;
            if (dateTo) f.date_to = dateTo;
        }
        if (statusFilter !== 'all') f.status = statusFilter;
        if (branchFilter !== 'active') f.branch = branchFilter;
        return f;
    }

    const distribution: DistributionItem[] = summary
        ? [
              { label: 'Completados', count: summary.completed, tone: 'safe' },
              { label: 'Entrega fallida', count: summary.failed ?? 0, tone: 'critical' },
              { label: 'Cancelados', count: summary.cancelled, tone: 'critical' },
              { label: 'Devoluciones', count: summary.refunded ?? 0, tone: 'warning' },
              { label: 'Abandonados', count: summary.abandoned, tone: 'warning' },
          ]
        : [];

    return (
        <PageShell title="Informes">
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="REPORTES"
                    title="Informes de Pedidos"
                    description={periodRange ? `${periodRange.from} — ${periodRange.to}` : undefined}
                    variant="editorial"
                    actions={
                        <ExportPdfButton
                            endpoint="/api/v1/exports/orders/pdf"
                            filters={buildPdfFilters()}
                            filename={`pedidos_${today}.pdf`}
                            disabled={!summary}
                        />
                    }
                />

                {/* Filtros: sede + período + estado en shell unificado.
                    Mobile: stack vertical; sm+: wrap horizontal. */}
                <div className="bg-card flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <BranchFilterTabs value={branchFilter} onChange={setBranchFilter} />
                    <PeriodTabs
                        options={PERIOD_OPTIONS}
                        value={period}
                        onChange={setPeriod}
                        specificDayValue="specific_day"
                        specificDay={specificDay}
                        onSpecificDayChange={setSpecificDay}
                        customValue="custom"
                        dateFrom={dateFrom}
                        dateTo={dateTo}
                        onDateFromChange={setDateFrom}
                        onDateToChange={setDateTo}
                        onApplyCustom={handleApplyCustom}
                        applyDisabled={!dateFrom || !dateTo}
                    />
                    <div className="grid flex-1 gap-1.5 sm:flex-initial">
                        <Label className="text-muted-foreground text-xs">Estado</Label>
                        <Select value={statusFilter} onValueChange={(v) => setStatusFilter(v as StatusFilter)}>
                            <SelectTrigger className="w-full sm:w-40">
                                <SelectValue />
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
                    <Button
                        variant="outline"
                        size="icon"
                        onClick={() => fetchReport(currentPage)}
                        disabled={loading}
                        aria-label="Actualizar"
                        className="self-end"
                    >
                        <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                    </Button>
                </div>

                {validationError && (
                    <Alert variant="destructive">
                        <AlertDescription>{validationError}</AlertDescription>
                    </Alert>
                )}

                {/* KPI Cards + Detalle */}
                {loading && !summary ? (
                    <>
                        <DashboardPanel title="Resumen del período">
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                {Array.from({ length: 5 }).map((_, i) => (
                                    <Skeleton key={i} className="h-20 w-full rounded-xl" />
                                ))}
                            </div>
                        </DashboardPanel>
                        <DashboardPanel title="Detalle de órdenes" contentClassName="p-0 pt-0">
                            <ReportsTableSkeleton rows={6} />
                        </DashboardPanel>
                    </>
                ) : fetchError ? (
                    <Alert variant="destructive">
                        <AlertDescription>{fetchError}</AlertDescription>
                    </Alert>
                ) : summary ? (
                    <>
                        <DashboardPanel title="Resumen del período">
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <StatTile value={summary.total_orders} label="Total órdenes" />
                                <StatTile value={summary.completed} label="Completadas" tone="safe" />
                                <StatTile value={summary.cancelled} label="Canceladas" tone="critical" />
                                <StatTile value={summary.abandoned} label="Abandonadas" tone="warning" />
                                <StatTile value={formatCurrency(summary.total_revenue)} label="Ingresos totales" tone="primary" />
                            </div>
                        </DashboardPanel>

                        <DashboardPanel title="Distribución de órdenes">
                            <DistributionBars items={distribution} total={summary.total_orders} />
                        </DashboardPanel>

                        {reportScope === 'consolidated' && perBranch.length > 0 && (
                            <DashboardPanel title="Comparativa entre sedes" contentClassName="p-0 pt-0">
                                <BranchComparisonTable rows={perBranch} />
                            </DashboardPanel>
                        )}

                        <DashboardPanel title="Detalle de órdenes" contentClassName="p-0 pt-0">
                            {orders.length === 0 ? (
                                <p className="text-muted-foreground px-6 py-8 text-sm">No hay órdenes en este período.</p>
                            ) : loading ? (
                                <ReportsTableSkeleton rows={6} />
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table bare>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>#</TableHead>
                                                <TableHead>Mesa / Tipo</TableHead>
                                                <TableHead>Estado</TableHead>
                                                <TableHead className="text-right">Total</TableHead>
                                                <TableHead className="text-right">Costo</TableHead>
                                                <TableHead>Fecha</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {orderedOrders.map((order, idx) => {
                                                const sid = order.table_session_id ?? null;
                                                const sessionGroup = sid ? sessionGroups.get(sid) : undefined;
                                                const isLinked = (sessionGroup?.length ?? 0) > 1;
                                                const prevSid = idx > 0 ? (orderedOrders[idx - 1].table_session_id ?? null) : null;
                                                const nextSid =
                                                    idx < orderedOrders.length - 1 ? (orderedOrders[idx + 1].table_session_id ?? null) : null;
                                                const isGroupStart = isLinked && sid !== prevSid;
                                                const isGroupEnd = isLinked && sid !== nextSid;
                                                const positionInGroup =
                                                    isLinked && sessionGroup ? sessionGroup.findIndex((o) => o.id === order.id) + 1 : 0;
                                                const borderColor = isLinked && sid !== null ? sessionBorderClass(sid) : '';
                                                const groupTotal = sessionGroup?.reduce((acc, o) => acc + o.total, 0) ?? 0;

                                                return (
                                                    <Fragment key={`row-${order.id}`}>
                                                        {isGroupStart && (
                                                            <TableRow
                                                                key={`group-${sid}`}
                                                                className={cn('bg-muted/30 hover:bg-muted/30 cursor-default border-l-4', borderColor)}
                                                            >
                                                                <TableCell colSpan={6} className="text-muted-foreground py-2 text-xs">
                                                                    <span className="inline-flex items-center gap-2">
                                                                        <Link2 className="h-3 w-3" />
                                                                        <span className="text-foreground font-semibold">
                                                                            Mesa {order.table_number ?? '?'}
                                                                        </span>
                                                                        <span>·</span>
                                                                        <span>{sessionGroup?.length} pedidos en la misma sesión</span>
                                                                        <span>·</span>
                                                                        <span className="tabular-nums">{formatCurrency(groupTotal)} total</span>
                                                                    </span>
                                                                </TableCell>
                                                            </TableRow>
                                                        )}
                                                        <TableRow
                                                            key={order.id}
                                                            onClick={() => void openOrderDetail(order.id)}
                                                            className={cn(
                                                                'cursor-pointer transition-colors',
                                                                isLinked
                                                                    ? cn(
                                                                          'bg-muted/20 hover:bg-muted/40 border-l-4',
                                                                          borderColor,
                                                                          isGroupEnd && 'border-b-border border-b',
                                                                      )
                                                                    : 'hover:bg-muted/40',
                                                            )}
                                                            title={
                                                                isLinked
                                                                    ? `Mesa ${order.table_number ?? '?'} · pedido ${positionInGroup} de ${sessionGroup?.length} en la misma sesión`
                                                                    : 'Ver detalle de la orden'
                                                            }
                                                        >
                                                            <TableCell className="text-muted-foreground font-mono">
                                                                {isLinked && (
                                                                    <span className="text-muted-foreground mr-1.5 text-[10px]">
                                                                        {positionInGroup}/{sessionGroup?.length}
                                                                    </span>
                                                                )}
                                                                #{order.id}
                                                            </TableCell>
                                                            <TableCell className="text-muted-foreground text-xs">
                                                                {order.table_number ? (
                                                                    <span>Mesa {order.table_number}</span>
                                                                ) : (
                                                                    <span className="capitalize">{order.order_type ?? '—'}</span>
                                                                )}
                                                            </TableCell>
                                                            <TableCell>
                                                                <Badge
                                                                    className={`border-transparent ${statusBadgeClass(orderStatuses, order.status)}`}
                                                                >
                                                                    {statusLabel(orderStatuses, order.status)}
                                                                </Badge>
                                                            </TableCell>
                                                            <TableCell className="text-right font-medium tabular-nums">
                                                                {formatCurrency(order.total)}
                                                            </TableCell>
                                                            <TableCell className="text-muted-foreground text-right tabular-nums">
                                                                {formatCurrency(order.cost)}
                                                            </TableCell>
                                                            <TableCell className="text-muted-foreground">{formatDate(order.ordered_at)}</TableCell>
                                                        </TableRow>
                                                    </Fragment>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}

                            {pagination && pagination.last_page > 1 && (
                                <div className="flex flex-col gap-2 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="text-muted-foreground text-xs">
                                        Mostrando {orders.length} de {pagination.total} órdenes
                                    </p>
                                    <div className="flex items-center justify-between gap-1 sm:justify-end">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => handlePageChange(currentPage - 1)}
                                            disabled={currentPage <= 1 || loading}
                                            className="flex-1 sm:flex-initial"
                                        >
                                            Anterior
                                        </Button>
                                        <span className="px-2 text-sm tabular-nums">
                                            {currentPage} / {pagination.last_page}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => handlePageChange(currentPage + 1)}
                                            disabled={currentPage >= pagination.last_page || loading}
                                            className="flex-1 sm:flex-initial"
                                        >
                                            Siguiente
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </DashboardPanel>
                    </>
                ) : null}

                <CashDrawerCard branchFilter={branchFilter} dateFrom={resolvedCashRange.from} dateTo={resolvedCashRange.to} />

                <CashSessionsCard branchFilter={branchFilter} />

                <SmsSentCard />
            </div>

            <OrderDetailModal order={selectedOrder} isOpen={selectedOrder !== null} onClose={() => setSelectedOrder(null)} />
        </PageShell>
    );
}
