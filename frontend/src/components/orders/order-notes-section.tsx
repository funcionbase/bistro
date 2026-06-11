import { Badge } from '@/components/ui/badge';
import type { KanbanOrderNote } from '@/hooks/use-orders';
import { MessageSquare, UserSquare2, UtensilsCrossed } from 'lucide-react';

interface OrderNotesSectionProps {
    notes: KanbanOrderNote[] | undefined | null;
    /** Compacto (tipografía menor) cuando se usa dentro de un modal denso. */
    compact?: boolean;
}

/**
 * Sección reutilizable que renderiza las notas grupales de la orden:
 *  - Notas del mesero (`author_role=waiter`, scope=group).
 *  - Notas grupales del cliente (`author_role=guest`, scope=group).
 *  - Alertas de cocina (`scope=kitchen_alert`).
 *
 * Las notas individuales del cliente (por item) NO entran acá — viven al
 * lado de cada line item. Devuelve null si no hay notas, para que el caller
 * pueda colocarla sin gates extra.
 */
export default function OrderNotesSection({ notes, compact = false }: OrderNotesSectionProps) {
    if (!notes || notes.length === 0) return null;

    return (
        <div>
            <div
                className={compact ? 'mb-2 flex items-center gap-1.5 text-sm font-medium' : 'mb-2 flex items-center gap-1.5 text-base font-semibold'}
            >
                <MessageSquare className="h-4 w-4" />
                <span>Notas de la mesa</span>
                <span className="text-muted-foreground text-xs font-normal">· {notes.length}</span>
            </div>
            <ul className="space-y-2">
                {notes.map((n) => (
                    <li key={n.id} className="border-border bg-muted/40 rounded-lg border p-3 text-sm leading-relaxed">
                        <p className="text-foreground whitespace-pre-line">{n.body}</p>
                        <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                            {n.scope === 'kitchen_alert' && (
                                <Badge
                                    variant="secondary"
                                    className="gap-1 bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]"
                                >
                                    <UtensilsCrossed className="h-3 w-3" />
                                    Alerta cocina
                                </Badge>
                            )}
                            {n.author_label && (
                                <span className="text-muted-foreground inline-flex items-center gap-1">
                                    <UserSquare2 className="h-3 w-3" />
                                    {n.author_role === 'waiter' ? 'Mesero' : 'Cliente'}:{' '}
                                    <span className="text-foreground font-medium">{n.author_label}</span>
                                </span>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
