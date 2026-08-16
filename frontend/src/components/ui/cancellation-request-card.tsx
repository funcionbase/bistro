import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { GuestBadge } from '@/components/ui/guest-badge';
import { cn } from '@/lib/utils';
import { Check, X } from 'lucide-react';
import { formatDateTimeShort } from '@/lib/datetime';

interface CancellationRequestCardProps {
    /** ID del CancellationRequest. */
    id: string;
    /** Nombre del comensal que solicitó la cancelación. */
    guestName: string;
    /** Detalle del item afectado. */
    itemName: string;
    /** Cantidad del item. */
    itemQuantity: number;
    /** Motivo del cliente (opcional). */
    reason?: string | null;
    /** Status actual: pending | approved | denied. */
    status: 'pending' | 'approved' | 'denied';
    /** Timestamp ISO de cuándo se solicitó. */
    createdAt?: string | null;
    /** Callback cuando el mesero aprueba la cancelación. */
    onApprove: () => void;
    /** Callback cuando el mesero la rechaza. */
    onDeny: () => void;
    /** Si true, deshabilita acciones. */
    disabled?: boolean;
    className?: string;
}

const statusLabel: Record<CancellationRequestCardProps['status'], string> = {
    pending: 'Pendiente',
    approved: 'Aprobada',
    denied: 'Negada',
};

const statusClass: Record<CancellationRequestCardProps['status'], string> = {
    pending: 'bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]',
    approved: 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]',
    denied: 'bg-muted text-muted-foreground',
};

/**
 * Card de una `cancellation_request`.
 *
 * Aparece en el detalle de la sesión del mesero cuando un comensal pidió
 * cancelar un item que ya está `approved` (post-aprobación, pre-cocina).
 * El mesero decide aprobar (→ item cancelled) o negar (→ item sigue su curso).
 */
export function CancellationRequestCard({
    id,
    guestName,
    itemName,
    itemQuantity,
    reason,
    status,
    createdAt,
    onApprove,
    onDeny,
    disabled = false,
    className,
}: CancellationRequestCardProps) {
    return (
        <div
            data-cancellation-id={id}
            className={cn(
                'border-border bg-card text-card-foreground rounded-2xl border p-4 space-y-3',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-3">
                <GuestBadge displayName={guestName} size="sm" />
                <Badge variant="secondary" className={statusClass[status]}>
                    {statusLabel[status]}
                </Badge>
            </div>
            <div>
                <p className="text-foreground text-sm font-medium">
                    {itemQuantity}× {itemName}
                </p>
                {reason && <p className="text-muted-foreground mt-1 text-xs italic">"{reason}"</p>}
                {createdAt && (
                    <p className="text-muted-foreground mt-1 text-[11px]">
                        Solicitado {formatDateTimeShort(createdAt)}
                    </p>
                )}
            </div>
            {status === 'pending' && (
                <div className="flex items-center justify-end gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={onDeny}
                        disabled={disabled}
                    >
                        <X className="mr-1 h-3.5 w-3.5" /> Negar
                    </Button>
                    <Button type="button" size="sm" variant="destructive" onClick={onApprove} disabled={disabled}>
                        <Check className="mr-1 h-3.5 w-3.5" /> Aprobar y cancelar
                    </Button>
                </div>
            )}
        </div>
    );
}
