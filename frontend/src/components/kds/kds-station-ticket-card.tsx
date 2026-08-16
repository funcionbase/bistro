import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { GuestBadge } from '@/components/ui/guest-badge';
import { cn } from '@/lib/utils';
import { Check, ChefHat, Flame } from 'lucide-react';

export type KdsSlaState = 'green' | 'amber' | 'red';

export type KdsTicketItemStatus = 'approved' | 'in_kitchen' | 'ready' | 'served';

export interface KdsStationTicketItem {
    id: string;
    name: string;
    quantity: number;
    notes: string | null;
    status: KdsTicketItemStatus;
    approved_at: string | null;
    in_kitchen_at: string | null;
    ready_at: string | null;
    sla_state: KdsSlaState;
    /** true si el item pertenece a la estación de este device-token. */
    is_own_station: boolean;
    /** Estación a la que está enrutado el item (id) — útil para mostrar de dónde es. */
    station_id: string | null;
}

export interface KdsStationTicketGroup {
    order_id: string;
    guest: { id: string; display_name: string } | null;
    table: { id: string | null; number: string | null } | null;
    order_notes: Array<{ id: string; scope: 'group' | 'kitchen_alert'; body: string }>;
    items: KdsStationTicketItem[];
    oldest_approved_at: string | null;
    sla_state: KdsSlaState;
}

interface KdsStationTicketCardProps {
    ticket: KdsStationTicketGroup;
    onMarkInKitchen: (itemId: string) => void;
    onMarkReady: (itemId: string) => void;
    busy?: boolean;
    className?: string;
    /**
     * Modo compacto para tickets en estado "listos · esperando entrega del
     * mesero". Reduce padding, tamaños de texto y oculta notas — el cocinero
     * ya no opera estos tickets, solo necesita verlos como recordatorio.
     */
    compact?: boolean;
}

const SLA_BORDER_TOKEN: Record<KdsSlaState, string> = {
    green: 'border-safe',
    amber: 'border-warning',
    red: 'border-critical',
};

const SLA_CHIP_CLASS: Record<KdsSlaState, string> = {
    green: 'bg-safe/15 text-safe border-safe/40',
    amber: 'bg-warning/15 text-warning border-warning/40',
    red: 'bg-critical/15 text-critical border-critical/40',
};

const SLA_LABEL: Record<KdsSlaState, string> = {
    green: 'En tiempo',
    amber: 'Atención',
    red: 'SLA vencido',
};

/**
 * Ticket de KDS por estación: una card por orden con los items
 * agrupados. Mostrar mesa/comensal/notas en el header, lista de items con
 * acción por estado, y borde de color según el peor SLA del grupo.
 *
 * Reutilizable desde la pantalla standalone `pages/kds/station.tsx`. Usa
 * tokens semánticos del DS (`safe / warning / critical`) en lugar de hex
 * hardcoded — el color de la estación se aplica solo al chip identitario
 * del header (viene de BD).
 */
export function KdsStationTicketCard({ ticket, onMarkInKitchen, onMarkReady, busy = false, className, compact = false }: KdsStationTicketCardProps) {
    return (
        <article
            data-order-id={ticket.order_id}
            data-sla={ticket.sla_state}
            data-compact={compact ? 'true' : 'false'}
            className={cn(
                'bg-card text-card-foreground flex min-w-0 flex-col rounded-2xl border-2 shadow-sm',
                compact ? 'gap-1.5 border p-2 opacity-90' : 'gap-3 p-3 sm:p-4',
                SLA_BORDER_TOKEN[ticket.sla_state],
                className,
            )}
        >
            <header className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p
                        className={cn(
                            'text-foreground leading-tight font-bold',
                            compact ? 'text-sm sm:text-base' : 'text-xl sm:text-2xl',
                        )}
                    >
                        {ticket.table?.number ? `Mesa ${ticket.table.number}` : `Orden #${ticket.order_id}`}
                    </p>
                    {ticket.guest && !compact && (
                        <div className="mt-1">
                            <GuestBadge displayName={ticket.guest.display_name} size="sm" />
                        </div>
                    )}
                </div>
                <Badge
                    variant="outline"
                    className={cn(
                        'shrink-0 border font-bold tracking-wide uppercase',
                        compact ? 'text-[9px]' : 'text-[10px]',
                        SLA_CHIP_CLASS[ticket.sla_state],
                    )}
                >
                    {compact ? 'Listo' : SLA_LABEL[ticket.sla_state]}
                </Badge>
            </header>

            {ticket.order_notes.length > 0 && !compact && (
                <Alert variant="default" className="border-warning/40 bg-warning/10">
                    <ChefHat className="h-4 w-4" />
                    <AlertDescription className="text-xs">
                        {ticket.order_notes.map((n) => (
                            <div key={n.id} className="leading-snug">
                                <span className="font-semibold uppercase">{n.scope === 'kitchen_alert' ? 'Alerta:' : 'Mesa:'}</span> {n.body}
                            </div>
                        ))}
                    </AlertDescription>
                </Alert>
            )}

            <ul className={cn('border-border', compact ? 'divide-border divide-y' : 'divide-border divide-y border-y')}>
                {ticket.items.map((item) => (
                    <li
                        key={item.id}
                        data-item-id={item.id}
                        data-status={item.status}
                        data-sla={item.sla_state}
                        data-own-station={item.is_own_station ? 'true' : 'false'}
                        className={cn(
                            'flex items-start gap-3',
                            compact ? 'py-1' : 'py-2',
                            !item.is_own_station && 'border-muted-foreground/30 border-l-4 pl-2',
                        )}
                    >
                        <div className="min-w-0 flex-1">
                            <p
                                className={cn(
                                    'leading-snug font-semibold',
                                    compact ? 'text-xs sm:text-sm' : 'text-base sm:text-lg',
                                    item.is_own_station ? 'text-foreground' : 'text-muted-foreground',
                                )}
                            >
                                <span className="tabular-nums">{item.quantity}×</span> {item.name}
                                {!item.is_own_station && !compact && (
                                    <span className="text-muted-foreground ml-2 text-xs font-normal italic">(otra estación)</span>
                                )}
                            </p>
                            {item.notes && !compact && (
                                <p className="text-foreground bg-warning/10 mt-1 rounded-md px-2 py-1 text-sm">
                                    <span className="text-warning">📝 {item.notes}</span>
                                </p>
                            )}
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-1.5">
                            {item.is_own_station && item.status === 'approved' && (
                                <Button type="button" size="sm" onClick={() => onMarkInKitchen(item.id)} disabled={busy}>
                                    <Flame className="mr-1 h-3.5 w-3.5" /> Entró
                                </Button>
                            )}
                            {item.is_own_station && item.status === 'in_kitchen' && (
                                <Button type="button" size="sm" onClick={() => onMarkReady(item.id)} disabled={busy}>
                                    <Check className="mr-1 h-3.5 w-3.5" /> Listo
                                </Button>
                            )}
                            {item.is_own_station && item.status === 'ready' && (
                                <span className="text-muted-foreground text-xs font-semibold">Listo · pendiente entrega</span>
                            )}
                            {!item.is_own_station && (
                                <span className="text-muted-foreground text-[10px] font-medium tracking-wide uppercase">
                                    {item.status === 'served'
                                        ? 'Servido'
                                        : item.status === 'ready'
                                          ? 'Listo'
                                          : item.status === 'in_kitchen'
                                            ? 'En cocina'
                                            : 'Pendiente'}
                                </span>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </article>
    );
}
