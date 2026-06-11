import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { Edit2, Trash2 } from 'lucide-react';

export type OrderItemStatus =
    | 'pending_approval'
    | 'approved'
    | 'in_kitchen'
    | 'ready'
    | 'served'
    | 'cancelled';

interface OrderItemCardProps {
    /** ID del registro (para keys y data attributes). */
    id: string;
    /** Nombre del plato (snapshot del menú al momento de pedir). */
    name: string;
    /** Cantidad. */
    quantity: number;
    /** Precio unitario formateado (string COP). */
    unitPriceFormatted: string;
    /** Total del item (qty × unit_price) formateado. */
    subtotalFormatted: string;
    /** Notas individuales — render como texto plano (escape automático React). */
    notes?: string | null;
    /** Estado del item. Controla badge y disponibilidad de acciones. */
    status: OrderItemStatus;
    /** Razón de cancelación si aplica — render bajo el badge. */
    cancellationReason?: string | null;
    /** Si está `submitted_at`, mostramos chip "Enviado al mesero". */
    isSubmitted?: boolean;
    /** Bool: hay un CancellationRequest pendiente del cliente sobre este item. */
    hasPendingCancellation?: boolean;
    /** Callback de edición — solo disponible si onEdit y el status permite. */
    onEdit?: () => void;
    /** Callback de cancelación — el caller decide la UX según status. */
    onCancel?: () => void;
    /** Si true, no muestra acciones (vista read-only para mesero/KDS/caja). */
    readOnly?: boolean;
    className?: string;
}

const statusLabels: Record<OrderItemStatus, string> = {
    pending_approval: 'Por aprobar',
    approved: 'Aprobado',
    in_kitchen: 'En cocina',
    ready: 'Listo',
    served: 'Entregado',
    cancelled: 'Cancelado',
};

// Tokens DS (--color-status-*) en lugar de paletas Tailwind crudas.
// pending_approval usa fondo neutro porque "esperando" no es ni positivo
// ni negativo; approved/in_kitchen/ready usan warning escalando intensidad
// con utilidades del DS; served usa safe; cancelled usa critical.
const statusBadgeClasses: Record<OrderItemStatus, string> = {
    pending_approval: 'bg-muted text-muted-foreground',
    approved: 'bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]',
    in_kitchen: 'bg-[color:var(--color-status-warning)]/25 text-[color:var(--color-status-warning)]',
    ready: 'bg-[color:var(--color-status-info)]/15 text-[color:var(--color-status-info)]',
    served: 'bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe)]',
    cancelled: 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]',
};

/**
 * Traduce el código interno de `cancellation_reason` a un texto legible
 * para el cliente. Los códigos crudos ('waiter', 'customer', 'waiter_approved')
 * vienen del backend y son enums — esta función les da copy paisa amable.
 */
function describeCancellationReason(reason: string | null | undefined): string | null {
    if (!reason) return null;
    switch (reason) {
        case 'waiter':
            return 'El mesero canceló este plato.';
        case 'customer':
            return 'Cancelado por ti.';
        case 'waiter_approved':
            return 'El mesero aprobó tu solicitud de cancelación.';
        case 'waiter_denied':
            return 'El mesero no aprobó la cancelación — el plato sigue en preparación.';
        default:
            return reason;
    }
}

/**
 * Card reutilizable para presentar un `order_item` (#191).
 *
 * Reusable en 4 contextos:
 *  - Carrito del comensal (con onEdit/onCancel si pending_approval).
 *  - Pantalla del mesero (typically readOnly, agrupado por comensal).
 *  - KDS (readOnly, con estado destacado).
 *  - Caja con pago dividido (readOnly, con subtotal y badge "Pagado").
 *
 * Los labels y clases de status vienen sincronizados con `config('orders.item_statuses.labels/badges')`
 * — si esos cambian en backend, actualizar este archivo.
 */
export function OrderItemCard({
    id,
    name,
    quantity,
    unitPriceFormatted,
    subtotalFormatted,
    notes,
    status,
    cancellationReason,
    isSubmitted = false,
    hasPendingCancellation = false,
    onEdit,
    onCancel,
    readOnly = false,
    className,
}: OrderItemCardProps) {
    // Una vez `submitted_at`, el mesero ya está viendo la versión enviada — el
    // comensal no debe poder modificar notas/cantidad porque crearía
    // ambigüedad entre lo que el mesero aprueba y lo que el cliente editó
    // después. La cancelación sí sigue permitida (el cliente puede arrepentirse
    // mientras espera aprobación).
    const canEdit = !readOnly && status === 'pending_approval' && !isSubmitted && !!onEdit;
    const canCancel = !readOnly && status !== 'cancelled' && status !== 'served' && !!onCancel;

    return (
        <div
            data-item-id={id}
            data-status={status}
            className={cn(
                'border-border bg-card text-card-foreground rounded-xl border p-3',
                status === 'cancelled' && 'opacity-60',
                className,
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline gap-1.5">
                        <span className="text-foreground text-sm font-semibold tabular-nums">
                            {quantity}×
                        </span>
                        <span className="text-foreground truncate text-sm font-medium">{name}</span>
                    </div>
                    {notes && <p className="text-muted-foreground mt-1 text-xs">{notes}</p>}
                    <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <Badge variant="secondary" className={statusBadgeClasses[status]}>
                            {statusLabels[status]}
                        </Badge>
                        {isSubmitted && status === 'pending_approval' && (
                            <span className="text-muted-foreground text-[11px]">Enviado al mesero</span>
                        )}
                        {hasPendingCancellation && (
                            <Badge
                                variant="secondary"
                                className="bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]"
                            >
                                Cancelación solicitada
                            </Badge>
                        )}
                    </div>
                    {status === 'cancelled' && describeCancellationReason(cancellationReason) && (
                        <p className="text-[color:var(--color-status-critical)] mt-1 text-[11px] font-medium">
                            {describeCancellationReason(cancellationReason)}
                        </p>
                    )}
                </div>
                <div className="text-right shrink-0">
                    <p className="text-foreground text-sm font-semibold tabular-nums">{subtotalFormatted}</p>
                    {quantity > 1 && (
                        <p className="text-muted-foreground text-[11px] tabular-nums">{unitPriceFormatted} c/u</p>
                    )}
                </div>
            </div>
            {(canEdit || canCancel) && (
                <div className="border-border mt-2.5 flex items-center justify-end gap-1.5 border-t pt-2">
                    {canEdit && (
                        <Button type="button" variant="ghost" size="sm" onClick={onEdit} className="h-7 px-2 text-xs">
                            <Edit2 className="mr-1 h-3 w-3" /> Editar
                        </Button>
                    )}
                    {canCancel && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={onCancel}
                            className="text-destructive hover:text-destructive h-7 px-2 text-xs"
                            disabled={hasPendingCancellation}
                        >
                            <Trash2 className="mr-1 h-3 w-3" /> Cancelar
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Skeleton de `OrderItemCard` para estados de carga del carrito del
 * comensal (`/t/:qr/menu`) y vistas read-only de mesero/KDS/caja.
 * Replica: qty + nombre, badge de status, subtotal.
 */
export function OrderItemCardSkeleton({ className }: { className?: string }) {
    return (
        <div
            aria-hidden
            className={cn('border-border bg-card rounded-xl border p-3', className)}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1 space-y-2">
                    <Skeleton className="h-4 w-3/5" />
                    <Skeleton className="h-5 w-24 rounded-full" />
                </div>
                <Skeleton className="h-4 w-14 shrink-0" />
            </div>
        </div>
    );
}
