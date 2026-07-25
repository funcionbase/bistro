import { AppLink } from '@/components/app-link';
import { Badge } from '@/components/ui/badge';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import { statusBadgeClass, statusLabel } from '@/lib/order-status';
import { MapPin, Phone, Plus, ShoppingBag, StickyNote, UserRound } from 'lucide-react';
import { useState } from 'react';

export interface ClientHistoryOrder {
    id: string;
    status: string;
    order_type: 'table' | 'delivery' | 'pickup' | null;
    total: number;
    discount_amount: number;
    items_count: number;
    ordered_at: string | null;
}

export interface ClientDetailNote {
    id: string;
    note: string;
    created_at: string | null;
    author: string | null;
}

export interface ClientDetail {
    contact: {
        id: string | null;
        name: string | null;
        phone: string;
        address: string | null;
        neighborhood: string | null;
        municipality_dane_code: string | null;
        municipality_label: string | null;
        /** F7: historial de pedidos no recibidos (flag informativo). */
        no_show_count?: number;
        fraud_flagged_at?: string | null;
    };
    notes: ClientDetailNote[];
    orders: ClientHistoryOrder[];
}

interface Props {
    isOpen: boolean;
    onClose: () => void;
    detail: ClientDetail | null;
    loading: boolean;
    error: string | null;
    onSelectOrder: (orderId: string) => void;
    /** Agrega una nota privada (client_notes). Devuelve al padre para refrescar. */
    onAddNote?: (contactId: string, note: string) => Promise<void>;
    /** F7: limpia el flag de fraude (chats.update). Oculto sin permiso. */
    onUnflagFraud?: () => Promise<void>;
}

function formatDate(iso: string | null): string {
    if (!iso) return '';
    return new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(iso));
}

export function ClientDetailModal({ isOpen, onClose, detail, loading, error, onSelectOrder, onAddNote, onUnflagFraud }: Props) {
    const formatCurrency = useCurrencyFormatter();
    const orderStatuses = useOrderStatuses();
    const title = detail?.contact?.name?.trim() || detail?.contact?.phone || 'Cliente';

    const [noteDraft, setNoteDraft] = useState('');
    const [savingNote, setSavingNote] = useState(false);
    const [unflagging, setUnflagging] = useState(false);

    const contactId = detail?.contact.id ?? null;
    const address = detail ? [detail.contact.address, detail.contact.neighborhood, detail.contact.municipality_label].filter(Boolean).join(', ') : '';

    async function submitNote() {
        const text = noteDraft.trim();
        if (!text || !contactId || !onAddNote || savingNote) return;
        setSavingNote(true);
        try {
            await onAddNote(contactId, text);
            setNoteDraft('');
        } finally {
            setSavingNote(false);
        }
    }

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
                    {/* F7: alerta informativa de fraude — el cajero decide cómo operar
                        (p. ej. exigir transferencia anticipada). Limpieza manual auditada. */}
                    {detail.contact.fraud_flagged_at && (
                        <div className="flex items-start justify-between gap-2 rounded-lg border border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 p-3 text-sm">
                            <div className="text-[color:var(--color-status-critical)]">
                                <p className="font-semibold">⚠️ Historial de pedidos no recibidos ({detail.contact.no_show_count ?? 0})</p>
                                <p className="text-xs">
                                    Sugerencia: pedir transferencia anticipada antes de aprobar sus pedidos a domicilio.
                                </p>
                            </div>
                            {onUnflagFraud && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={unflagging}
                                    onClick={() => {
                                        setUnflagging(true);
                                        void onUnflagFraud().finally(() => setUnflagging(false));
                                    }}
                                >
                                    Quitar alerta
                                </Button>
                            )}
                        </div>
                    )}
                    <section className="space-y-1.5 text-sm">
                        <div className="flex items-center gap-2">
                            <Phone className="text-muted-foreground h-4 w-4" />
                            <span>{detail.contact.phone}</span>
                        </div>
                        {address && (
                            <div className="flex items-start gap-2">
                                <MapPin className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0" />
                                <span className="text-muted-foreground">{address}</span>
                            </div>
                        )}
                        {contactId && (
                            <AppLink href={`/clients/${contactId}`}>
                                <Button variant="outline" size="sm" className="mt-1">
                                    <UserRound className="mr-1 h-4 w-4" />
                                    Ver cliente
                                </Button>
                            </AppLink>
                        )}
                    </section>

                    {/* Notas privadas UNIFICADAS: las mismas client_notes que /clients. */}
                    <section>
                        <div className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                            <StickyNote className="h-4 w-4" />
                            <span>Notas privadas ({detail.notes.length})</span>
                        </div>
                        {onAddNote && contactId && (
                            <div className="mb-2 flex gap-2">
                                <input
                                    value={noteDraft}
                                    onChange={(e) => setNoteDraft(e.target.value.slice(0, 1000))}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') void submitNote();
                                    }}
                                    placeholder="Agregar una nota…"
                                    className="border-input bg-background focus-visible:ring-ring flex-1 rounded-md border px-3 py-1.5 text-sm shadow-sm focus-visible:ring-1 focus-visible:outline-none"
                                />
                                <Button size="sm" onClick={() => void submitNote()} disabled={savingNote || noteDraft.trim() === ''}>
                                    <Plus className="h-4 w-4" />
                                </Button>
                            </div>
                        )}
                        {detail.notes.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Sin notas.</p>
                        ) : (
                            <ul className="space-y-2">
                                {detail.notes.map((n) => (
                                    <li key={n.id} className="bg-muted/40 rounded-md border p-2 text-sm">
                                        <p className="whitespace-pre-wrap">{n.note}</p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {[n.author, formatDate(n.created_at)].filter(Boolean).join(' · ')}
                                        </p>
                                    </li>
                                ))}
                            </ul>
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
                            <ul className="max-h-[45vh] space-y-2 overflow-y-auto pr-1">
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
                                                        <span className="font-mono text-sm font-semibold">#{o.id.slice(0, 8).toUpperCase()}</span>
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
