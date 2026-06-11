import type { SortColumn, SortDirection } from '@/components/deliveries/sort-header';
import type { DaySalesOrder } from '@/hooks/use-day-sales';
import type { useOrderStatuses } from '@/hooks/use-order-statuses';
import { useCallback, useMemo, useState } from 'react';

type OrderStatuses = ReturnType<typeof useOrderStatuses>;

interface UseDaySalesSortReturn {
    sortColumn: SortColumn;
    sortDirection: SortDirection;
    toggleSort: (col: SortColumn) => void;
    sortedOrders: DaySalesOrder[];
}

/** Display value de la columna "Cliente / Mesa / Dirección" — usado tanto
 * para mostrar como para ordenar alfabéticamente. */
export function locationOf(order: DaySalesOrder): string {
    if (order.table_number) return `Mesa ${order.table_number}`;
    if (order.delivery_address) return order.delivery_address;
    return order.client_phone ?? '';
}

/** Para ordenar por repartidor: nombre o "Sin asignar" para deliveries sin
 * courier; "—" para tipos no-delivery (ordena al final). */
export function courierOf(order: DaySalesOrder): string {
    if (order.delivery?.deliverer?.name) return order.delivery.deliverer.name;
    if (order.order_type === 'delivery') return 'Sin asignar';
    return '~~~'; // tildes ordenan al final lexicográficamente
}

/**
 * Manejo del ordenamiento de la tabla de ventas del día (solo desktop).
 * Click en un header ciclea asc → desc. Comportamiento idéntico al que
 * vivía inline en la página.
 */
export function useDaySalesSort(orders: DaySalesOrder[], orderStatuses: OrderStatuses): UseDaySalesSortReturn {
    const [sortColumn, setSortColumn] = useState<SortColumn>('datetime');
    const [sortDirection, setSortDirection] = useState<SortDirection>('desc');

    const toggleSort = useCallback((col: SortColumn) => {
        setSortColumn((prevCol) => {
            if (prevCol === col) {
                setSortDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
                return prevCol;
            }
            setSortDirection('asc');
            return col;
        });
    }, []);

    const sortedOrders = useMemo(() => {
        const dir = sortDirection === 'asc' ? 1 : -1;
        const arr = [...orders];
        arr.sort((a, b) => {
            let cmp = 0;
            switch (sortColumn) {
                case 'id':
                    cmp = a.id.localeCompare(b.id);
                    break;
                case 'datetime': {
                    const at = a.ordered_at ? new Date(a.ordered_at).getTime() : 0;
                    const bt = b.ordered_at ? new Date(b.ordered_at).getTime() : 0;
                    cmp = at - bt;
                    break;
                }
                case 'type':
                    cmp = (a.order_type ?? '').localeCompare(b.order_type ?? '', 'es');
                    break;
                case 'status': {
                    const labels = orderStatuses.labels as Record<string, string>;
                    const la = labels[a.status] ?? a.status;
                    const lb = labels[b.status] ?? b.status;
                    cmp = la.localeCompare(lb, 'es');
                    break;
                }
                case 'location':
                    cmp = locationOf(a).localeCompare(locationOf(b), 'es');
                    break;
                case 'courier':
                    cmp = courierOf(a).localeCompare(courierOf(b), 'es');
                    break;
                case 'total':
                    cmp = a.total - b.total;
                    break;
            }
            return cmp * dir;
        });
        return arr;
    }, [orders, sortColumn, sortDirection, orderStatuses]);

    return { sortColumn, sortDirection, toggleSort, sortedOrders };
}
