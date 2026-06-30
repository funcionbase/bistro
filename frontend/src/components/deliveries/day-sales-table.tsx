import { SortHeader, type SortColumn, type SortDirection } from '@/components/deliveries/sort-header';
import { OrderStatusBadge } from '@/components/orders/order-status-badge';
import type { DaySalesOrder } from '@/hooks/use-day-sales';
import { getOrderTypeMeta } from '@/lib/order-type';
import { ShoppingBag } from 'lucide-react';

/** "08/05/2026 07:53 a. m." en TZ Bogotá. */
export function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-CO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
        timeZone: 'America/Bogota',
    });
}

interface DaySalesTableProps {
    /** Órdenes ya ordenadas para la tabla desktop. */
    sortedOrders: DaySalesOrder[];
    /** Órdenes sin ordenar para la lista mobile. */
    orders: DaySalesOrder[];
    sortColumn: SortColumn;
    sortDirection: SortDirection;
    onToggleSort: (col: SortColumn) => void;
    formatCurrency: (value: number) => string;
    onOpenOrder: (orderId: string) => void;
}

/**
 * Tabla de ventas del día. Renderiza una tabla ordenable en desktop y una
 * lista de tarjetas en mobile. Extraído de la página de ventas del día
 * para reducir su tamaño — comportamiento idéntico.
 */
export function DaySalesTable({ sortedOrders, orders, sortColumn, sortDirection, onToggleSort, formatCurrency, onOpenOrder }: DaySalesTableProps) {
    return (
        <>
            {/* Desktop table */}
            <div className="bg-card hidden overflow-hidden rounded-lg border shadow-sm sm:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-xs uppercase">
                        <tr>
                            <SortHeader label="#" column="id" activeColumn={sortColumn} direction={sortDirection} onClick={onToggleSort} />
                            <SortHeader
                                label="Fecha/Hora"
                                column="datetime"
                                activeColumn={sortColumn}
                                direction={sortDirection}
                                onClick={onToggleSort}
                            />
                            <SortHeader label="Tipo" column="type" activeColumn={sortColumn} direction={sortDirection} onClick={onToggleSort} />
                            <SortHeader
                                label="Estado"
                                column="status"
                                activeColumn={sortColumn}
                                direction={sortDirection}
                                onClick={onToggleSort}
                            />
                            <SortHeader
                                label="Cliente / Mesa / Dirección"
                                column="location"
                                activeColumn={sortColumn}
                                direction={sortDirection}
                                onClick={onToggleSort}
                            />
                            <SortHeader
                                label="Repartidor"
                                column="courier"
                                activeColumn={sortColumn}
                                direction={sortDirection}
                                onClick={onToggleSort}
                            />
                            <SortHeader
                                label="Total"
                                column="total"
                                activeColumn={sortColumn}
                                direction={sortDirection}
                                onClick={onToggleSort}
                                align="right"
                            />
                        </tr>
                    </thead>
                    <tbody>
                        {sortedOrders.map((order) => {
                            const typeMeta = getOrderTypeMeta(order.order_type);
                            const TypeIcon = typeMeta?.icon ?? ShoppingBag;
                            return (
                                <tr
                                    key={order.id}
                                    onClick={() => onOpenOrder(order.id)}
                                    className="hover:bg-muted/40 cursor-pointer border-t"
                                >
                                    <td className="text-muted-foreground px-3 py-2 font-mono">#{order.id}</td>
                                    <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">
                                        {formatDateTime(order.ordered_at)}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className="inline-flex items-center gap-1.5 text-xs">
                                            <TypeIcon className="h-3.5 w-3.5" />
                                            {typeMeta?.label ?? '—'}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <OrderStatusBadge status={order.status} />
                                    </td>
                                    <td className="text-muted-foreground max-w-[240px] truncate px-3 py-2 text-xs">
                                        {order.table_number
                                            ? `Mesa ${order.table_number}`
                                            : order.delivery_address
                                              ? order.delivery_address
                                              : (order.client_phone ?? '—')}
                                    </td>
                                    <td className="text-muted-foreground px-3 py-2 text-xs">
                                        {order.delivery?.deliverer?.name ?? (order.order_type === 'delivery' ? 'Sin asignar' : '—')}
                                    </td>
                                    <td className="px-3 py-2 text-right font-semibold tabular-nums">{formatCurrency(order.total)}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {/* Mobile list */}
            <div className="space-y-2 sm:hidden">
                {orders.map((order) => {
                    const typeMeta = getOrderTypeMeta(order.order_type);
                    const TypeIcon = typeMeta?.icon ?? ShoppingBag;
                    return (
                        <button
                            key={order.id}
                            onClick={() => onOpenOrder(order.id)}
                            className="bg-card hover:bg-muted/40 w-full rounded-lg border p-3 text-left shadow-sm"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <TypeIcon className="text-muted-foreground h-4 w-4" />
                                    <span className="font-semibold">#{order.id}</span>
                                    <OrderStatusBadge status={order.status} />
                                </div>
                                <span className="font-semibold tabular-nums">{formatCurrency(order.total)}</span>
                            </div>
                            <div className="text-muted-foreground mt-1 flex items-center justify-between text-xs">
                                <span className="truncate">
                                    {order.table_number
                                        ? `Mesa ${order.table_number}`
                                        : order.delivery_address
                                          ? order.delivery_address
                                          : (order.client_phone ?? typeMeta?.label ?? '—')}
                                </span>
                                <span className="whitespace-nowrap">{formatDateTime(order.ordered_at)}</span>
                            </div>
                            {order.delivery && (
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Repartidor: {order.delivery.deliverer?.name ?? 'Sin asignar'}
                                </p>
                            )}
                        </button>
                    );
                })}
            </div>
        </>
    );
}
