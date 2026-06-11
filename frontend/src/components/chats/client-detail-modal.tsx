import { Badge } from '@/components/ui/badge';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import { statusBadgeClass, statusLabel } from '@/lib/order-status';
import { Phone, ShoppingBag, StickyNote } from 'lucide-react';

// Reemplazado por helpers canónicos `statusLabel()` / `statusBadgeClass()` desde lib/order-status.

export interface ClientHistoryOrder {
    id: string;
    status: string;
    order_type: 'table' | 'delivery' | 'pickup' | null;
    total: number;
    discount_amount: number;
    items_count: number;
    ordered_at: string | null;
}

export interface ClientDetail {
    contact: {
        id: string | null;
        name: string | null;
        phone: string;
        notes: string | null;
    };
    orders: ClientHistoryOrder[];
}

interface Props {
    isOpen: boolean;
    onClose: () => void;
    detail: ClientDetail | null;
    loading: boolean;
    error: string | null;
    onSelectOrder: (orderId: string) => void;
}

function formatDate(iso: string | null): string {
    if (!iso) return '';
    return new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(iso));
}

export function ClientDetailModal({ isOpen, onClose, detail, loading, error, onSelectOrder }: Props) {
    const formatCurrency = useCurrencyFormatter();
    const orderStatuses = useOrderStatuses();
    const title = detail?.contact?.name?.trim() || detail?.contact?.phone || 'Cliente';

    return (
        <BottomSheetDialog isOpen={isOpen} onClose={onClose} title={title}>
            {loading && !detail ? (
                <div className="space-y-2">
                    <Skeleton className="h-16 w-full" />
                    <Skeleton className="h-24 w-full" />
                </div>
            ) : error ? (
                <p className="text-sm text-[color:var(--color-status-critical)]">{error}</p>
            ) : detail ? (
                <div className="space-y-4">
                    <section className="space-y-1.5 text-sm">
                        <div className="flex items-center gap-2">
                            <Phone className="text-muted-foreground h-4 w-4" />
                            <span>{detail.contact.phone}</span>
                        </div>
                        {detail.contact.notes && (
                            <div className="flex items-start gap-2">
                                <StickyNote className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0" />
                                <p className="text-muted-foreground whitespace-pre-wrap">{detail.contact.notes}</p>
                            </div>
                        )}
                    </section>

                    <section>
                        <div className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                            <ShoppingBag className="h-4 w-4" />
                            <span>Historial de órdenes ({detail.orders.length})</span>
                        </div>
                        {detail.orders.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Este cliente aún no tiene órdenes.</p>
                        ) : (
                            <ul className="max-h-[55vh] space-y-2 overflow-y-auto pr-1">
                                {detail.orders.map((o) => {
                                    const meta = {
                                        label: statusLabel(orderStatuses, o.status),
                                        badgeClass: statusBadgeClass(orderStatuses, o.status),
                                    };
                                    return (
                                        <li key={o.id}>
                                            <button
                                                type="button"
                                                onClick={() => onSelectOrder(o.id)}
                                                className="hover:bg-muted/50 flex w-full items-start justify-between gap-3 rounded-lg border p-3 text-left transition"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-mono text-sm font-semibold">#{o.id}</span>
                                                        <Badge className={meta.badgeClass}>{meta.label}</Badge>
                                                    </div>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {formatDate(o.ordered_at)} · {o.items_count} {o.items_count === 1 ? 'ítem' : 'ítems'}
                                                    </p>
                                                </div>
                                                <span className="shrink-0 text-sm font-medium tabular-nums">{formatCurrency(o.total)}</span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>
                </div>
            ) : null}
        </BottomSheetDialog>
    );
}
