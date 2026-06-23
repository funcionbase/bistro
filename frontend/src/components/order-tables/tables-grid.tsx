import { TableCard } from '@/components/orders/table-card';
import { Button } from '@/components/ui/button';
import type { ActiveSession } from '@/hooks/use-table-grid';
import type { useOrderStatuses } from '@/hooks/use-order-statuses';
import type { TableOrder } from '@/hooks/use-tables';
import { statusLabel } from '@/lib/order-status';
import { Cog, Utensils } from 'lucide-react';

interface TablesGridProps {
    /** Números/etiquetas de mesa a renderizar (admin + órdenes vivas). */
    tableNumbers: string[];
    /** Primera orden activa por número de mesa. */
    ordersByTable: Map<string, TableOrder>;
    /** Sesión grupal activa por número de mesa. */
    activeSessionByTable: Map<string, ActiveSession>;
    orderStatuses: ReturnType<typeof useOrderStatuses>;
    formatCurrency: (value: number) => string;
    /** Abre el menú de acciones de una sesión grupal (cobrar / liberar). */
    onSessionAction: (session: ActiveSession, tableNumber: string) => void;
    /** Abre el modal de detalle de una orden de mesa tradicional. */
    onOpenOrder: (orderId: string) => void;
    /** Abre la caja para una mesa libre. */
    onOpenCashier: (tableNumber: string) => void;
}

/**
 * Grilla de mesas del POS. Combina mesas definidas en admin con órdenes
 * vivas y sesiones grupales. Muestra un empty state cuando no hay mesas.
 * Extraído de la página de mesas — comportamiento idéntico.
 */
export function TablesGrid({
    tableNumbers,
    ordersByTable,
    activeSessionByTable,
    orderStatuses,
    formatCurrency,
    onSessionAction,
    onOpenOrder,
    onOpenCashier,
}: TablesGridProps) {
    if (tableNumbers.length === 0) {
        return (
            <div className="border-border bg-card text-card-foreground flex flex-col items-center gap-3 rounded-2xl border px-6 py-12 text-center">
                <Utensils className="text-muted-foreground h-10 w-10" />
                <div>
                    <p className="text-foreground text-base font-semibold">No hay mesas registradas en esta sede</p>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Crea las mesas físicas desde el módulo de administración para que aparezcan acá.
                    </p>
                </div>
                <Button size="sm" asChild>
                    <a href="/company/tables">
                        <Cog className="mr-1 h-4 w-4" /> Gestionar mesas
                    </a>
                </Button>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            {tableNumbers.map((n) => {
                const order = ordersByTable.get(n);
                const occupied = !!order;
                const session = activeSessionByTable.get(n) ?? null;

                if (session) {
                    // Mesa con sesión grupal activa: tono info, badge "En sesión".
                    // La orden `completed` en el tablero solo significa "entregada al
                    // cliente" — NO que se cobró. El cobro es obligatorio antes de
                    // liberar la mesa y solo ocurre desde Mesas o Caja.
                    const hasActiveOrder = session.order_id !== null && session.items_consumable_count > 0;
                    return (
                        <TableCard
                            key={n}
                            number={n}
                            occupied
                            total={order ? formatCurrency(order.total) : undefined}
                            statusLabel={session.order_status ? statusLabel(orderStatuses, session.order_status) : undefined}
                            onClick={() => onSessionAction(session, n)}
                            groupSession={{
                                guestsCount: session.guests_count,
                                hasActiveOrder,
                                itemsInFlight: session.items_consumable_count,
                                releaseAction: (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="w-full text-xs"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onSessionAction(session, n);
                                        }}
                                    >
                                        Cerrar mesa
                                    </Button>
                                ),
                            }}
                        />
                    );
                }

                return (
                    <TableCard
                        key={n}
                        number={n}
                        occupied={occupied}
                        itemCount={order?.item_count}
                        total={order ? formatCurrency(order.total) : undefined}
                        statusLabel={order ? statusLabel(orderStatuses, order.status) : undefined}
                        onClick={() => (occupied ? onOpenOrder(order!.id) : onOpenCashier(n))}
                    />
                );
            })}
        </div>
    );
}
