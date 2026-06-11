import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { GuestBadge } from '@/components/ui/guest-badge';
import { cn } from '@/lib/utils';

interface ItemForCashier {
    id: string;
    name: string;
    quantity: number;
    unit_price: string;
    subtotal: string;
    status: string;
    paid_at: string | null;
    paid_receipt_id: string | null;
}

interface GuestBreakdown {
    id: string;
    display_name: string;
    phone: string;
    subtotal: string;
    unpaid_amount: string;
    items: ItemForCashier[];
}

interface GuestItemListProps {
    guest: GuestBreakdown;
    /** Items seleccionados para cobrar (controlado por el caller). */
    selected?: Record<string, boolean>;
    onToggle?: (itemId: string) => void;
    onSelectAllUnpaid?: () => void;
    onPay?: () => void;
    onRefundItem?: (itemId: string) => void;
    /** Si true, sólo muestra info — sin checkboxes ni acciones. */
    readOnly?: boolean;
    className?: string;
}

/**
 * Lista de items consumidos por un comensal con totals y selección para
 * cobro parcial (#191 Fase 6).
 *
 * Reusable en caja (con checkboxes + CTAs de pago y refund) y en vista
 * mesero/historial (readOnly). Cuando un item ya tiene `paid_at`, se
 * muestra deshabilitado con badge "Pagado" + opción de refund.
 */
export function GuestItemList({
    guest,
    selected = {},
    onToggle,
    onSelectAllUnpaid,
    onPay,
    onRefundItem,
    readOnly = false,
    className,
}: GuestItemListProps) {
    const hasUnpaid = guest.items.some((i) => !i.paid_at);

    return (
        <div
            data-guest-id={guest.id}
            className={cn(
                'border-border bg-card text-card-foreground space-y-3 rounded-2xl border p-4',
                className,
            )}
        >
            <header className="flex items-center justify-between gap-3">
                <GuestBadge displayName={guest.display_name} phoneMasked={maskPhone(guest.phone)} size="md" />
                <div className="text-right">
                    <p className="text-muted-foreground text-[11px]">Pendiente</p>
                    <p className="text-foreground text-sm font-semibold tabular-nums">
                        {formatCurrency(Number.parseFloat(guest.unpaid_amount))}
                    </p>
                </div>
            </header>

            {!readOnly && hasUnpaid && onSelectAllUnpaid && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onSelectAllUnpaid}
                    className="h-7 px-2 text-xs"
                >
                    Seleccionar todos los pendientes
                </Button>
            )}

            <ul className="space-y-1.5">
                {guest.items.map((item) => (
                    <li key={item.id} className="flex items-start gap-2">
                        {!readOnly && onToggle && !item.paid_at && (
                            <Checkbox
                                className="mt-1.5"
                                checked={!!selected[item.id]}
                                onCheckedChange={() => onToggle(item.id)}
                            />
                        )}
                        <div className="flex-1">
                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                <span>
                                    <span className="tabular-nums">{item.quantity}×</span>{' '}
                                    <span className="text-foreground">{item.name}</span>
                                </span>
                                <span className="text-foreground tabular-nums">
                                    {formatCurrency(Number.parseFloat(item.subtotal))}
                                </span>
                            </div>
                            <div className="mt-1 flex items-center gap-1.5">
                                {item.paid_at ? (
                                    <>
                                        <Badge
                                            variant="secondary"
                                            className="bg-[color:var(--color-status-success)]/15 text-[color:var(--color-status-success)]"
                                        >
                                            Pagado
                                        </Badge>
                                        {!readOnly && onRefundItem && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => onRefundItem(item.id)}
                                                className="text-destructive hover:text-destructive h-6 px-1.5 text-[11px]"
                                            >
                                                Devolver
                                            </Button>
                                        )}
                                    </>
                                ) : (
                                    <Badge
                                        variant="secondary"
                                        className="bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]"
                                    >
                                        Pendiente
                                    </Badge>
                                )}
                            </div>
                        </div>
                    </li>
                ))}
            </ul>

            {!readOnly && onPay && (
                <div className="flex justify-end">
                    <Button type="button" size="sm" onClick={onPay} disabled={!hasUnpaid}>
                        Cobrar a este comensal
                    </Button>
                </div>
            )}
        </div>
    );
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
}

function maskPhone(phone: string): string {
    if (phone.length !== 10) return phone;
    return `${phone.slice(0, 3)} *** ${phone.slice(6)}`;
}
