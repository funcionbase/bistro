import { Timer } from '@/components/deliveries/timer';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { DELIVERY_REASON_LABELS, type Delivery } from '@/types';
import { CheckCircle2, LoaderCircle, MapPin, MoreVertical, Phone } from 'lucide-react';
import { formatCurrency as formatCurrencyCOP } from '@/lib/formatters';

export type MyDeliveryAction = 'complete' | 'open_actions';

interface MyDeliveryCardProps {
    delivery: Delivery;
    /** Notifica al parent qué acción se disparó. El parent decide POST + estado. */
    onAction: (action: MyDeliveryAction, delivery: Delivery) => void;
    /** Marca un loader en el botón de acción principal mientras se completa el POST. */
    busy?: boolean;
}

/**
 * Card mobile-first del domiciliario.
 *
 * Diferencias con `DeliveryCard` (admin):
 *  - Layout vertical: info compacta arriba, CTAs grandes al pie del card
 *    para alcance one-hand.
 *  - Tap-to-call (`tel:`) y tap-to-maps (`https://maps.google.com/?q=...`)
 *    en cliente y dirección.
 *  - Tokens del DS (`var(--color-status-*)`, `bg-card`, `border-border`,
 *    `text-muted-foreground`). Sin colores hardcoded.
 *  - Acciones secundarias bajo un menú "..." (MoreVertical) que abre un
 *    bottom-sheet — long-press no funciona consistente cross-browser
 *    mobile, así que preferimos un trigger explícito.
 *  - Cards en `cancelled` muestran motivo (status_change_reason o
 *    cancellation_reason) para que el courier entienda el cierre.
 */
export function MyDeliveryCard({ delivery, onAction, busy = false }: MyDeliveryCardProps) {
    const order = delivery.order;
    const clientPhone = order?.client_phone ?? null;
    const clientName = (order as { client_name?: string } | undefined)?.client_name ?? null;
    const address = (order as { delivery_address?: string } | undefined)?.delivery_address ?? null;

    const isPending = delivery.status === 'pending';
    const isCompleted = delivery.status === 'completed';
    const isCancelled = delivery.status === 'cancelled';

    const accentClass = isCompleted
        ? 'border-l-4 border-l-[color:var(--color-status-success)]'
        : isCancelled
          ? 'border-l-4 border-l-[color:var(--color-status-critical)]'
          : 'border-l-4 border-l-[color:var(--color-status-warning)]';

    const mapsHref = address ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}` : null;

    return (
        <Card className={cn('overflow-hidden', accentClass)}>
            <div className="flex flex-col gap-3 p-4">
                <header className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1 space-y-0.5">
                        <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Orden #{delivery.order_id}</p>
                        <p className="text-foreground truncate text-base font-semibold">{clientName ?? 'Sin nombre'}</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => onAction('open_actions', delivery)}
                        className="text-muted-foreground hover:bg-muted focus-visible:ring-ring -mr-1 rounded-full p-2 transition-colors focus:outline-none focus-visible:ring-2"
                        aria-label="Más acciones"
                    >
                        <MoreVertical className="h-5 w-5" aria-hidden="true" />
                    </button>
                </header>

                <dl className="space-y-1.5 text-sm">
                    {clientPhone && (
                        <div className="flex items-center gap-2">
                            <Phone className="text-muted-foreground h-4 w-4 shrink-0" aria-hidden="true" />
                            <a href={`tel:${clientPhone}`} className="text-foreground truncate font-medium underline-offset-2 hover:underline">
                                {clientPhone}
                            </a>
                        </div>
                    )}
                    {address && (
                        <div className="flex items-start gap-2">
                            <MapPin className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            {mapsHref ? (
                                <a
                                    href={mapsHref}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-foreground underline-offset-2 hover:underline"
                                >
                                    {address}
                                </a>
                            ) : (
                                <span className="text-foreground">{address}</span>
                            )}
                        </div>
                    )}
                    <div className="text-muted-foreground flex items-center justify-between gap-3 pt-1 text-xs">
                        <Timer startTime={delivery.assigned_at} format="minutes" />
                        {order?.total !== undefined && (
                            <span className="text-foreground font-medium tabular-nums">{formatCurrency(Number(order.total))}</span>
                        )}
                    </div>
                </dl>

                {isCancelled && (delivery.status_change_reason || delivery.cancellation_reason) && (
                    <p className="bg-muted text-muted-foreground rounded-md px-3 py-2 text-xs">
                        {reasonLabel(delivery.status_change_reason) ?? delivery.cancellation_reason}
                    </p>
                )}

                {isPending && (
                    <Button type="button" size="lg" className="w-full" disabled={busy} onClick={() => onAction('complete', delivery)}>
                        {busy ? <LoaderCircle className="mr-1 h-4 w-4 animate-spin" /> : <CheckCircle2 className="mr-1 h-4 w-4" />}
                        Marcar entregado
                    </Button>
                )}
            </div>
        </Card>
    );
}

function formatCurrency(value: number): string {
    if (!Number.isFinite(value)) {
        return '—';
    }
    return formatCurrencyCOP(value);
}

function reasonLabel(reason: Delivery['status_change_reason']): string | null {
    if (reason === 'error_usuario') {
        return `${DELIVERY_REASON_LABELS.error_usuario} — la entrega vuelve a estar pendiente.`;
    }
    if (reason === 'pedido_rechazado') {
        return `${DELIVERY_REASON_LABELS.pedido_rechazado}.`;
    }
    return null;
}
