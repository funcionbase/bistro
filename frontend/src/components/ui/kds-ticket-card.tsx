import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { GuestBadge } from '@/components/ui/guest-badge';
import { TimeSinceCounter } from '@/components/ui/time-since-counter';
import { cn } from '@/lib/utils';
import { Check, ChefHat, Flame } from 'lucide-react';

interface KdsTicket {
    id: string;
    order_id: string;
    name: string;
    quantity: number;
    notes: string | null;
    status: 'approved' | 'in_kitchen' | 'ready';
    approved_at: string | null;
    in_kitchen_at: string | null;
    ready_at: string | null;
    guest: { id: string; display_name: string } | null;
    table: { id: string | null; number: string | null } | null;
    order_notes: Array<{ id: string; scope: 'group' | 'kitchen_alert'; body: string }>;
}

interface KdsTicketCardProps {
    ticket: KdsTicket;
    onMarkInKitchen: () => void;
    onMarkReady: () => void;
    /**
     * Marca el ticket como entregado (mark-served), sacándolo del KDS. Es el
     * handoff al mesero (mesa) o al repartidor (domicilio): ambos comparten el
     * estado `ready`. Si no se pasa, el ticket `ready` solo se muestra.
     */
    onMarkServed?: () => void;
    disabled?: boolean;
    className?: string;
    /**
     * Modo compacto para tickets en `ready` (esperando entrega del mesero).
     * Reduce padding y tamaños de texto — el cocinero ya no opera estos
     * tickets, sólo los necesita ver como recordatorio.
     */
    compact?: boolean;
}

/**
 * Ticket del KDS (#191 Fase 5).
 *
 * Card grande, tipografía alta, contraste fuerte — pensado para pantalla
 * fija en cocina. Muestra mesa, comensal, plato, cantidad, notas
 * individuales y notas grupales/kitchen_alert replicadas como banner.
 * Acción primaria depende del status:
 *  - approved → botón "Entró a cocina" (mark in_kitchen).
 *  - in_kitchen → botón "Listo" (mark ready).
 *  - ready → mostrar como pendiente de entrega del mesero (sin CTA).
 */
export function KdsTicketCard({ ticket, onMarkInKitchen, onMarkReady, onMarkServed, disabled, className, compact = false }: KdsTicketCardProps) {
    const since =
        ticket.status === 'approved'
            ? ticket.approved_at
            : ticket.status === 'in_kitchen'
                ? ticket.in_kitchen_at
                : ticket.ready_at;

    const tone =
        ticket.status === 'approved'
            ? 'border-border'
            : ticket.status === 'in_kitchen'
              ? 'border-[color:var(--color-status-warning)]/50'
              : 'border-[color:var(--color-status-success)]/50';

    return (
        <div
            data-ticket-id={ticket.id}
            data-status={ticket.status}
            data-compact={compact ? 'true' : 'false'}
            className={cn(
                'bg-card text-card-foreground flex flex-col rounded-2xl border-2 shadow-sm',
                compact ? 'gap-1.5 p-2 opacity-90' : 'gap-3 p-4',
                tone,
                className,
            )}
        >
            <header className="flex items-start justify-between gap-2">
                <div>
                    <p
                        className={cn(
                            'text-foreground font-bold leading-tight',
                            compact ? 'text-sm' : 'text-2xl',
                        )}
                    >
                        {ticket.table?.number ? `Mesa ${ticket.table.number}` : 'Sin mesa'}
                    </p>
                    {ticket.guest && !compact && (
                        <div className="mt-1">
                            <GuestBadge displayName={ticket.guest.display_name} size="sm" />
                        </div>
                    )}
                </div>
                <TimeSinceCounter sinceIso={since} />
            </header>

            <div className={compact ? 'space-y-0.5' : 'space-y-1'}>
                <p className={cn('text-foreground font-semibold', compact ? 'text-xs' : 'text-lg')}>
                    <span className="tabular-nums">{ticket.quantity}×</span> {ticket.name}
                </p>
                {ticket.notes && !compact && (
                    <p className="rounded-md bg-[color:var(--color-status-warning)]/10 px-2 py-1 text-sm">
                        <span className="text-[color:var(--color-status-warning)]">📝 {ticket.notes}</span>
                    </p>
                )}
            </div>

            {ticket.order_notes.length > 0 && !compact && (
                <Alert variant="warning">
                    <ChefHat className="h-4 w-4" />
                    <AlertDescription className="text-xs">
                        {ticket.order_notes.map((n) => (
                            <div key={n.id} className="leading-snug">
                                <span className="font-semibold uppercase">
                                    {n.scope === 'kitchen_alert' ? 'Alerta' : 'Mesa'}:
                                </span>{' '}
                                {n.body}
                            </div>
                        ))}
                    </AlertDescription>
                </Alert>
            )}

            {!compact && (
                <div className="border-border mt-auto flex items-center justify-end gap-2 border-t pt-2">
                    {ticket.status === 'approved' && (
                        <Button type="button" size="sm" onClick={onMarkInKitchen} disabled={disabled}>
                            <Flame className="mr-1 h-3.5 w-3.5" /> Entró a cocina
                        </Button>
                    )}
                    {ticket.status === 'in_kitchen' && (
                        <Button type="button" size="sm" onClick={onMarkReady} disabled={disabled}>
                            <Check className="mr-1 h-3.5 w-3.5" /> Listo
                        </Button>
                    )}
                    {ticket.status === 'ready' &&
                        (onMarkServed ? (
                            <Button type="button" size="sm" variant="outline" onClick={onMarkServed} disabled={disabled}>
                                <Check className="mr-1 h-3.5 w-3.5" /> Marcar entregado
                            </Button>
                        ) : (
                            <span className="text-xs font-semibold text-[color:var(--color-status-info)]">Esperando entrega</span>
                        ))}
                </div>
            )}

            {compact && ticket.status === 'ready' && onMarkServed && (
                <div className="mt-auto">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-7 w-full px-2 text-xs"
                        onClick={onMarkServed}
                        disabled={disabled}
                    >
                        <Check className="mr-1 h-3 w-3" /> Entregado
                    </Button>
                </div>
            )}
        </div>
    );
}
