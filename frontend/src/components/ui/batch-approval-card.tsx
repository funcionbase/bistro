import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { GuestBadge } from '@/components/ui/guest-badge';
import { OrderItemCard } from '@/components/ui/order-item-card';
import { cn } from '@/lib/utils';
import { Check, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatCurrency } from '@/lib/formatters';

interface BatchItem {
    id: string;
    name: string;
    quantity: number;
    unit_price: string;
    notes?: string | null;
    status:
        | 'pending_approval'
        | 'approved'
        | 'in_kitchen'
        | 'ready'
        | 'served'
        | 'cancelled';
}

interface BatchApprovalCardProps {
    /** Nombre del comensal dueño de la tanda. */
    guestName: string;
    /** Teléfono enmascarado para mostrar. */
    guestPhoneMasked?: string;
    /** Timestamp ISO del submitted_at de la tanda (para mostrar "hace 2 min"). */
    submittedAt: string | null;
    /** Items de la tanda — typically status=pending_approval. */
    items: ReadonlyArray<BatchItem>;
    /** Callback con los item_ids seleccionados al confirmar aprobación. */
    onApprove: (itemIds: string[]) => void;
    /** Callback con item_id al rechazar uno. */
    onReject: (itemId: string) => void;
    /** Si true, deshabilita acciones (loading global). */
    disabled?: boolean;
    className?: string;
}

/**
 * Card de una "tanda" pendiente de aprobación (#191 Fase 4).
 *
 * Tanda = items con `submitted_at` cercano del mismo comensal. El mesero
 * los ve agrupados con selección múltiple (default: todos seleccionados) y
 * un botón "Aprobar tanda". Rechazar es por item con motivo opcional —
 * cada uno entra al audit log con `cancellation_reason=waiter`.
 *
 * Usado en `pages/orders/table-sessions/show.tsx`.
 */
export function BatchApprovalCard({
    guestName,
    guestPhoneMasked,
    submittedAt,
    items,
    onApprove,
    onReject,
    disabled = false,
    className,
}: BatchApprovalCardProps) {
    const [selected, setSelected] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(items.map((i) => [i.id, true])),
    );

    const selectedIds = useMemo(
        () => Object.entries(selected).filter(([, on]) => on).map(([id]) => id),
        [selected],
    );

    const allSelected = items.every((i) => selected[i.id]);

    const total = useMemo(
        () =>
            selectedIds
                .map((id) => items.find((i) => i.id === id))
                .reduce((acc, i) => (i ? acc + Number.parseFloat(i.unit_price) * i.quantity : acc), 0),
        [items, selectedIds],
    );

    return (
        <div
            className={cn(
                'border-border bg-card text-card-foreground rounded-2xl border p-4 space-y-3',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-3">
                <GuestBadge displayName={guestName} phoneMasked={guestPhoneMasked} size="md" status="awaiting" />
                <span className="text-muted-foreground text-xs">
                    {submittedAt ? formatRelative(submittedAt) : 'Sin enviar'}
                </span>
            </div>

            <div className="flex items-center gap-2 border-y border-dashed py-2">
                <Checkbox
                    checked={allSelected}
                    onCheckedChange={(checked) =>
                        setSelected(Object.fromEntries(items.map((i) => [i.id, !!checked])))
                    }
                />
                <span className="text-xs">Seleccionar todos</span>
                <span className="text-muted-foreground ml-auto text-xs tabular-nums">
                    {selectedIds.length}/{items.length} · {formatCurrency(total)}
                </span>
            </div>

            <ul className="space-y-2">
                {items.map((item) => (
                    <li key={item.id} className="flex items-start gap-2">
                        <Checkbox
                            className="mt-3"
                            checked={!!selected[item.id]}
                            onCheckedChange={(checked) =>
                                setSelected((prev) => ({ ...prev, [item.id]: !!checked }))
                            }
                        />
                        <div className="flex-1">
                            <OrderItemCard
                                id={item.id}
                                name={item.name}
                                quantity={item.quantity}
                                unitPriceFormatted={formatCurrency(Number.parseFloat(item.unit_price))}
                                subtotalFormatted={formatCurrency(
                                    Number.parseFloat(item.unit_price) * item.quantity,
                                )}
                                notes={item.notes}
                                status={item.status}
                                readOnly
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive mt-2 h-7 px-2 text-xs"
                            onClick={() => onReject(item.id)}
                            disabled={disabled}
                            title="Rechazar este item"
                        >
                            <X className="h-3 w-3" />
                        </Button>
                    </li>
                ))}
            </ul>

            <div className="flex justify-end">
                <Button
                    type="button"
                    size="sm"
                    onClick={() => onApprove(selectedIds)}
                    disabled={disabled || selectedIds.length === 0}
                >
                    <Check className="mr-1 h-3.5 w-3.5" /> Aprobar tanda ({selectedIds.length})
                </Button>
            </div>
        </div>
    );
}


function formatRelative(iso: string): string {
    const date = new Date(iso);
    const diffSec = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (diffSec < 60) return 'hace segundos';
    if (diffSec < 3600) return `hace ${Math.floor(diffSec / 60)} min`;
    if (diffSec < 86400) return `hace ${Math.floor(diffSec / 3600)} h`;
    return date.toLocaleString('es-CO');
}
