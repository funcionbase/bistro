import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Clock, LoaderCircle, MapPin, Package, Phone, ShoppingBag } from 'lucide-react';

export interface AvailableOrder {
    id: string;
    client_phone: string | null;
    client_name?: string | null;
    delivery_address: string | null;
    total: number | string;
    status: string;
    branch_id: string;
    table_number?: string | null;
    ordered_at: string;
}

interface AvailableOrderCardProps {
    order: AvailableOrder;
    onTake: (orderId: string) => void;
    /** Marca el botón con loader mientras el POST está en vuelo. */
    busy?: boolean;
}

const currency = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
});

/**
 * Card de orden disponible para auto-asignación (#119).
 *
 * Mobile-first: la CTA "Tomar entrega" ocupa todo el ancho al pie del
 * card. Información mínima necesaria (cliente, dirección, monto, tiempo
 * desde que se hizo el pedido) para que el courier decida sin abrir el
 * detalle.
 */
export function AvailableOrderCard({ order, onTake, busy = false }: AvailableOrderCardProps) {
    const total = typeof order.total === 'string' ? Number(order.total) : order.total;
    const minutesAgo = computeMinutesAgo(order.ordered_at);

    return (
        <Card className="overflow-hidden border-l-4 border-l-[color:var(--color-status-info)]">
            <div className="flex flex-col gap-3 p-4">
                <header className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1 space-y-0.5">
                        <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                            Orden #{order.id} · {order.status}
                        </p>
                        <p className="text-foreground truncate text-base font-semibold">{order.client_name ?? 'Sin nombre'}</p>
                    </div>
                    <span className="bg-muted text-muted-foreground inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs">
                        <ShoppingBag className="h-3 w-3" aria-hidden="true" />
                        {Number.isFinite(total) ? currency.format(total) : '—'}
                    </span>
                </header>

                <dl className="space-y-1.5 text-sm">
                    {order.client_phone && (
                        <div className="flex items-center gap-2">
                            <Phone className="text-muted-foreground h-4 w-4 shrink-0" aria-hidden="true" />
                            <span className="text-foreground truncate">{order.client_phone}</span>
                        </div>
                    )}
                    {order.delivery_address && (
                        <div className="flex items-start gap-2">
                            <MapPin className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span className="text-foreground">{order.delivery_address}</span>
                        </div>
                    )}
                    <div className="text-muted-foreground flex items-center gap-1 pt-1 text-xs">
                        <Clock className="h-3.5 w-3.5" aria-hidden="true" />
                        Pedida hace {minutesAgo} min
                    </div>
                </dl>

                <Button type="button" size="lg" className="w-full" disabled={busy} onClick={() => onTake(order.id)}>
                    {busy ? <LoaderCircle className="mr-1 h-4 w-4 animate-spin" /> : <Package className="mr-1 h-4 w-4" />}
                    Tomar entrega
                </Button>
            </div>
        </Card>
    );
}

function computeMinutesAgo(iso: string): number {
    const ts = new Date(iso).getTime();
    if (Number.isNaN(ts)) return 0;
    return Math.max(0, Math.floor((Date.now() - ts) / 60_000));
}
