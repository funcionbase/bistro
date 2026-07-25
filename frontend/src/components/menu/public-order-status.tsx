import { useAutoPolling } from '@/hooks/use-auto-polling';
import { resolveBackendUrl } from '@/lib/api';
import { formatCurrency } from '@/lib/coupon-helpers';
import { ClipboardList } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface PublicCartOrder {
    id: string;
    short_code: string;
    status: string;
    status_label: string;
    order_type: string;
    total: string;
    tip_amount: string;
    payment_preference: string | null;
    ordered_at: string | null;
}

interface Props {
    /** Token de la sesión de carta (?cart={uuid}). */
    cartToken: string;
    /** Notifica al padre la lista de órdenes en cada poll (append-or-new). */
    onOrders?: (orders: PublicCartOrder[]) => void;
}

/**
 * Tarjeta de estado de los pedidos del carrito público (CA6, F3): el cliente
 * ve TODAS las órdenes de su sesión de carta y su estado operado por caja,
 * con polling de 12s (pausa con la pestaña oculta). Sin websockets.
 */
export function PublicOrderStatus({ cartToken, onOrders }: Props) {
    const [orders, setOrders] = useState<PublicCartOrder[]>([]);
    const onOrdersRef = useRef(onOrders);
    onOrdersRef.current = onOrders;

    const fetchOrders = useCallback(async () => {
        try {
            const res = await fetch(resolveBackendUrl(`/api/v1/public/cart/${encodeURIComponent(cartToken)}/orders`), {
                headers: { Accept: 'application/json' },
                credentials: 'omit',
            });
            if (!res.ok) return;
            const json = (await res.json().catch(() => null)) as { data?: PublicCartOrder[] } | null;
            if (json?.data) {
                setOrders(json.data);
                onOrdersRef.current?.(json.data);
            }
        } catch {
            // Polling fire-and-forget: un fallo de red no rompe la carta.
        }
    }, [cartToken]);

    useEffect(() => {
        void fetchOrders();
    }, [fetchOrders]);

    useAutoPolling({ intervalMs: 12_000, onTick: fetchOrders, pauseWhenHidden: true });

    if (orders.length === 0) return null;

    return (
        <section className="border-border bg-card mx-auto mb-4 w-full max-w-2xl rounded-lg border p-4 shadow-sm">
            <h2 className="text-foreground mb-2 flex items-center gap-2 text-sm font-semibold">
                <ClipboardList className="h-4 w-4" aria-hidden />
                {orders.length === 1 ? 'Tu pedido' : 'Tus pedidos'}
            </h2>
            <ul className="divide-border divide-y">
                {orders.map((order) => (
                    <li key={order.id} className="flex items-center justify-between gap-3 py-2">
                        <div className="min-w-0">
                            <p className="text-foreground text-sm font-medium">
                                Pedido #{order.short_code}
                                <span className="text-muted-foreground ml-2 text-xs">
                                    {order.order_type === 'delivery' ? 'Domicilio' : 'Para llevar'}
                                </span>
                            </p>
                            <p className="text-muted-foreground text-xs tabular-nums">{formatCurrency(Number(order.total))}</p>
                        </div>
                        <span className="border-border text-foreground shrink-0 rounded-full border px-2.5 py-0.5 text-xs font-medium">
                            {order.status_label}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
