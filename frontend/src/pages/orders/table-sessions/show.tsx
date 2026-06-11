import { DianOrderActions } from '@/components/dian/dian-order-actions';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { BatchApprovalCard } from '@/components/ui/batch-approval-card';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Button } from '@/components/ui/button';
import { CancellationRequestCard } from '@/components/ui/cancellation-request-card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { GuestBadge } from '@/components/ui/guest-badge';
import { NotesEditor } from '@/components/ui/notes-editor';
import { OrderItemCard, type OrderItemStatus } from '@/components/ui/order-item-card';
import { PageHeader } from '@/components/ui/page-header';
import { TableSessionDetailSkeleton } from '@/components/ui/table-session-detail-skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { apiFetch } from '@/lib/api';
import { shortOrderCode } from '@/lib/order-code';
import { AlertCircle, CheckCircle2, ChefHat, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

interface Guest {
    id: string;
    display_name: string;
    phone: string;
    joined_at: string | null;
}

interface ItemForBatch {
    id: string;
    menu_item_id: string;
    name: string;
    unit_price: string;
    quantity: number;
    notes: string | null;
    status: OrderItemStatus;
    cancellation_reason: string | null;
    guest_id: string | null;
    submitted_at: string | null;
}

interface PendingBatch {
    guest_id: string;
    guest_name: string;
    submitted_at: string | null;
    items: ItemForBatch[];
}

interface CancellationRequestItem {
    id: string;
    order_item_id: string;
    guest_id: string;
    status: 'pending' | 'approved' | 'denied';
    reason: string | null;
    resolved_at: string | null;
}

interface GroupNote {
    id: string;
    scope: 'group' | 'kitchen_alert';
    body: string;
    author_type: string | null;
    author_id: string | null;
    created_at: string | null;
}

interface ApprovedOrderItem {
    id: string;
    menu_item_id: string | null;
    name: string;
    unit_price: string;
    quantity: number;
    notes: string | null;
    status: OrderItemStatus;
    cancellation_reason: string | null;
    guest_id: string | null;
    guest_label: string | null;
    approved_at: string | null;
    in_kitchen_at: string | null;
    ready_at: string | null;
    served_at: string | null;
}

interface ApprovedOrder {
    id: string;
    status: string;
    total: string;
    ordered_at: string | null;
    items: ApprovedOrderItem[];
}

interface SessionDetail {
    id: string;
    status: 'open' | 'locked' | 'closed' | 'expired';
    accepts_new_guests: boolean;
    opened_at: string | null;
    expires_at: string | null;
    table: { id: string | null; number: string | null; capacity: number | null };
    order: { id: string; status: string; total: string } | null;
    /** Órdenes derivadas (una por tanda aprobada). Cada una con sus items. */
    approved_orders: ApprovedOrder[];
    guests: Guest[];
    pending_batches: PendingBatch[];
    items_by_status: Record<OrderItemStatus, ItemForBatch[]>;
    group_notes: GroupNote[];
    cancellation_requests: CancellationRequestItem[];
}

const tabs = [
    { key: 'pending_approval', label: 'Por aprobar' },
    { key: 'approved', label: 'Aprobados' },
    { key: 'in_kitchen', label: 'Cocina' },
    { key: 'ready', label: 'Listos' },
    { key: 'served', label: 'Servidos' },
    { key: 'cancelled', label: 'Cancelados' },
] as const;

/**
 * Detalle de una sesión de mesa para el mesero (#191 Fase 4).
 *
 * Estructura: header con info de mesa, panel principal con tandas
 * pendientes + tabs por estado de item, side panel con cancelaciones
 * abiertas y notas grupales/cocina. Polling cada 3s.
 */
export default function TableSessionShow() {
    const id = window.location.pathname.split('/').pop() ?? '';

    const sessionId = id;
    const [detail, setDetail] = useState<SessionDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const [closeEmptyOpen, setCloseEmptyOpen] = useState(false);
    const [waiterNoteOpen, setWaiterNoteOpen] = useState(false);
    const [waiterNoteScope, setWaiterNoteScope] = useState<'group' | 'kitchen_alert'>('group');
    const [waiterNoteBody, setWaiterNoteBody] = useState('');

    const fetchDetail = useCallback(async () => {
        try {
            const resp = await apiFetch(`/api/v1/table-sessions/${sessionId}`);
            if (!resp.ok) throw new Error('No pudimos cargar la sesión.');
            const json = (await resp.json()) as { data: SessionDetail };
            setDetail(json.data);
            setError(null);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setLoading(false);
        }
    }, [sessionId]);

    useEffect(() => {
        void fetchDetail();
    }, [fetchDetail]);

    useEffect(() => {
        if (!detail || detail.status === 'closed' || detail.status === 'expired') return;
        const id = window.setInterval(() => void fetchDetail(), 3000);
        return () => window.clearInterval(id);
    }, [detail, fetchDetail]);

    const guestById = useMemo(() => {
        const map: Record<string, Guest> = {};
        (detail?.guests ?? []).forEach((g) => {
            map[g.id] = g;
        });
        return map;
    }, [detail]);

    const handleError = (err: unknown, fallback = 'Acción fallida.') => {
        if (err instanceof Response) {
            void err.json().then((data) => setActionError((data as { message?: string }).message ?? fallback));
        } else if (err instanceof Error) {
            setActionError(err.message);
        } else {
            setActionError(fallback);
        }
    };

    const mutate = async (path: string, body?: Record<string, unknown>, method: 'POST' | 'PATCH' = 'POST') => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/table-sessions/${sessionId}/${path}`, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: body ? JSON.stringify(body) : undefined,
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Acción rechazada.');
            }
            await fetchDetail();
        } catch (err) {
            handleError(err);
        } finally {
            setBusy(false);
        }
    };

    const approveBatch = (itemIds: string[]) => void mutate('approve-batch', { item_ids: itemIds });

    const rejectItem = (itemId: string) => void mutate(`items/${itemId}/reject`, { reason: 'Rechazado por mesero' });

    const cancelInKitchen = (itemId: string, reason: string) => void mutate(`items/${itemId}/cancel`, { reason });

    const resolveCancellation = async (crId: string, decision: 'approved' | 'denied') => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/cancellation-requests/${crId}/resolve`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ decision }),
            });
            if (!resp.ok) throw new Error('No pudimos resolver la solicitud.');
            await fetchDetail();
        } catch (err) {
            handleError(err);
        } finally {
            setBusy(false);
        }
    };

    const submitWaiterNote = async () => {
        if (waiterNoteBody.trim() === '') return;
        await mutate('notes', { scope: waiterNoteScope, body: waiterNoteBody });
        setWaiterNoteOpen(false);
        setWaiterNoteBody('');
    };

    const closeEmpty = async () => {
        await mutate('close-empty');
        setCloseEmptyOpen(false);
    };

    return (
        <PageShell title={detail?.table?.number ? `Mesa ${detail.table.number}` : 'Sesión'}>
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {loading ? (
                    <TableSessionDetailSkeleton />
                ) : (
                    <>
                        <PageHeader
                            eyebrow={detail?.order ? `Mesa con QR · Orden #${shortOrderCode(detail.order.id)}` : 'Mesa con QR'}
                            title={detail?.table?.number ? `Mesa ${detail.table.number}` : `Sesión #${sessionId}`}
                            description={
                                detail
                                    ? `${detail.guests.length} comensales · ${detail.status === 'locked' ? 'Mesa en curso' : 'Esperando primera tanda'}`
                                    : ''
                            }
                            actions={
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setWaiterNoteOpen(true)}
                                        disabled={busy || detail?.status === 'closed' || detail?.status === 'expired'}
                                        className="w-full sm:w-auto"
                                    >
                                        Agregar nota
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setCloseEmptyOpen(true)}
                                        disabled={busy || detail?.status === 'closed' || detail?.status === 'expired'}
                                        className="text-destructive hover:text-destructive w-full sm:w-auto"
                                    >
                                        Cerrar mesa vacía
                                    </Button>
                                </div>
                            }
                        />

                        {/* HU #235 — acciones DIAN inline. Solo renderiza cuando hay
                            una orden vinculada a la sesión; el componente decide
                            internamente qué mostrar según permisos y documento DIAN
                            existente para el order_id. */}
                        {detail?.order && (
                            <DianOrderActions
                                orderId={detail.order.id}
                                orderStatus={detail.order.status}
                            />
                        )}

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                        {actionError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{actionError}</AlertDescription>
                            </Alert>
                        )}

                        {detail ? (
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                <div className="space-y-6 lg:col-span-2">
                                    <section className="space-y-2">
                                        <h2 className="text-foreground text-sm font-semibold">Comensales</h2>
                                        <div className="flex flex-wrap gap-2">
                                            {detail.guests.map((g) => (
                                                <GuestBadge
                                                    key={g.id}
                                                    displayName={g.display_name}
                                                    phoneMasked={maskPhone(g.phone)}
                                                    size="sm"
                                                    status={detail.status === 'open' ? 'active' : 'awaiting'}
                                                />
                                            ))}
                                        </div>
                                    </section>

                                    {(detail.approved_orders ?? []).length > 0 && (
                                        <section className="space-y-3">
                                            <div className="flex items-baseline justify-between">
                                                <h2 className="text-foreground text-sm font-semibold">
                                                    Órdenes de la mesa
                                                    <span className="text-muted-foreground ml-2 text-xs font-normal">
                                                        · {detail.approved_orders.length}
                                                    </span>
                                                </h2>
                                                <span className="text-muted-foreground text-xs">
                                                    Cada aprobación del mesero genera una orden separada.
                                                </span>
                                            </div>
                                            <ul className="space-y-3">
                                                {detail.approved_orders.map((o) => {
                                                    const orderTotal = o.items.reduce(
                                                        (acc, it) =>
                                                            it.status === 'cancelled' ? acc : acc + Number.parseFloat(it.unit_price) * it.quantity,
                                                        0,
                                                    );
                                                    return (
                                                        <li key={o.id} className="border-border bg-card rounded-xl border p-3">
                                                            <div className="mb-2 flex items-baseline justify-between gap-2">
                                                                <div className="flex items-baseline gap-2">
                                                                    <p className="text-foreground text-sm font-semibold" title={o.id}>
                                                                        Orden #{shortOrderCode(o.id)}
                                                                    </p>
                                                                    <span className="text-muted-foreground text-xs">· {o.status}</span>
                                                                    {o.ordered_at && (
                                                                        <span className="text-muted-foreground text-xs">
                                                                            ·{' '}
                                                                            {new Date(o.ordered_at).toLocaleTimeString('es-CO', {
                                                                                hour: '2-digit',
                                                                                minute: '2-digit',
                                                                            })}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                <p className="text-foreground text-sm font-semibold tabular-nums">
                                                                    {formatCurrency(orderTotal)}
                                                                </p>
                                                            </div>
                                                            <ul className="space-y-1">
                                                                {o.items.map((it) => (
                                                                    <li key={it.id} className="flex items-start justify-between gap-2 text-sm">
                                                                        <div className="min-w-0 flex-1">
                                                                            <p className="text-foreground">
                                                                                <span className="font-medium tabular-nums">{it.quantity}×</span>{' '}
                                                                                {it.name}
                                                                                {it.status === 'cancelled' && (
                                                                                    <span className="ml-1 text-xs font-medium text-[color:var(--color-status-critical)]">
                                                                                        · cancelado
                                                                                    </span>
                                                                                )}
                                                                            </p>
                                                                            {it.guest_label && (
                                                                                <p className="text-muted-foreground text-xs">{it.guest_label}</p>
                                                                            )}
                                                                            {it.notes && (
                                                                                <p className="bg-muted/50 mt-0.5 rounded px-2 py-0.5 text-xs italic">
                                                                                    {it.notes}
                                                                                </p>
                                                                            )}
                                                                        </div>
                                                                        <span className="text-foreground shrink-0 text-sm tabular-nums">
                                                                            {formatCurrency(Number.parseFloat(it.unit_price) * it.quantity)}
                                                                        </span>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        </section>
                                    )}

                                    {detail.pending_batches.length > 0 && (
                                        <section className="space-y-3">
                                            <h2 className="text-foreground text-sm font-semibold">Tandas pendientes de aprobación</h2>
                                            <div className="space-y-3">
                                                {detail.pending_batches.map((batch, i) => (
                                                    <BatchApprovalCard
                                                        key={`${batch.guest_id}-${batch.submitted_at ?? i}`}
                                                        guestName={batch.guest_name}
                                                        guestPhoneMasked={maskPhone(guestById[batch.guest_id]?.phone ?? '')}
                                                        submittedAt={batch.submitted_at}
                                                        items={batch.items.map((i) => ({
                                                            id: i.id,
                                                            name: i.name,
                                                            quantity: i.quantity,
                                                            unit_price: i.unit_price,
                                                            notes: i.notes,
                                                            status: i.status,
                                                        }))}
                                                        onApprove={approveBatch}
                                                        onReject={rejectItem}
                                                        disabled={busy}
                                                    />
                                                ))}
                                            </div>
                                        </section>
                                    )}

                                    <section className="space-y-3">
                                        <h2 className="text-foreground text-sm font-semibold">Items de la mesa</h2>
                                        <Tabs defaultValue="approved" className="w-full">
                                            <TabsList className="flex w-full flex-wrap">
                                                {tabs.map((tab) => (
                                                    <TabsTrigger key={tab.key} value={tab.key}>
                                                        {tab.label}
                                                        {detail.items_by_status[tab.key]?.length ? (
                                                            <Badge variant="secondary" className="ml-1.5">
                                                                {detail.items_by_status[tab.key].length}
                                                            </Badge>
                                                        ) : null}
                                                    </TabsTrigger>
                                                ))}
                                            </TabsList>
                                            {tabs.map((tab) => (
                                                <TabsContent key={tab.key} value={tab.key} className="space-y-2 pt-3">
                                                    {(detail.items_by_status[tab.key] ?? []).length === 0 ? (
                                                        <p className="text-muted-foreground text-center text-sm">Sin items.</p>
                                                    ) : (
                                                        <ul className="space-y-2">
                                                            {detail.items_by_status[tab.key].map((item) => (
                                                                <li key={item.id} className="space-y-1">
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
                                                                        cancellationReason={item.cancellation_reason}
                                                                        readOnly
                                                                    />
                                                                    {(item.status === 'in_kitchen' || item.status === 'ready') && (
                                                                        <CancelInKitchenInline
                                                                            onConfirm={(reason) => cancelInKitchen(item.id, reason)}
                                                                            disabled={busy}
                                                                        />
                                                                    )}
                                                                    {item.guest_id && guestById[item.guest_id] && (
                                                                        <p className="text-muted-foreground pl-3 text-[11px]">
                                                                            {guestById[item.guest_id].display_name}
                                                                        </p>
                                                                    )}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </TabsContent>
                                            ))}
                                        </Tabs>
                                    </section>
                                </div>

                                <aside className="space-y-6">
                                    <section className="space-y-2">
                                        <h2 className="text-foreground text-sm font-semibold">Resumen</h2>
                                        <div className="border-border bg-card text-card-foreground space-y-2 rounded-2xl border p-4">
                                            <div className="flex items-center justify-between text-sm">
                                                <span className="text-muted-foreground">Estado</span>
                                                <Badge variant="secondary">
                                                    {detail.status === 'open' ? 'Abierta' : detail.status === 'locked' ? 'En curso' : detail.status}
                                                </Badge>
                                            </div>
                                            <div className="flex items-center justify-between text-sm">
                                                <span className="text-muted-foreground">Total estimado</span>
                                                <span className="text-foreground font-semibold tabular-nums">
                                                    {detail.order ? formatCurrency(Number.parseFloat(detail.order.total)) : '—'}
                                                </span>
                                            </div>
                                        </div>
                                    </section>

                                    {detail.cancellation_requests.filter((c) => c.status === 'pending').length > 0 && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">Cancelaciones pendientes</h2>
                                            <div className="space-y-2">
                                                {detail.cancellation_requests
                                                    .filter((c) => c.status === 'pending')
                                                    .map((cr) => {
                                                        const item = Object.values(detail.items_by_status)
                                                            .flat()
                                                            .find((i) => i.id === cr.order_item_id);
                                                        const guest = guestById[cr.guest_id];
                                                        return (
                                                            <CancellationRequestCard
                                                                key={cr.id}
                                                                id={cr.id}
                                                                guestName={guest?.display_name ?? 'Comensal'}
                                                                itemName={item?.name ?? `Item #${cr.order_item_id}`}
                                                                itemQuantity={item?.quantity ?? 1}
                                                                reason={cr.reason}
                                                                status={cr.status}
                                                                onApprove={() => void resolveCancellation(cr.id, 'approved')}
                                                                onDeny={() => void resolveCancellation(cr.id, 'denied')}
                                                                disabled={busy}
                                                            />
                                                        );
                                                    })}
                                            </div>
                                        </section>
                                    )}

                                    {detail.group_notes.length > 0 && (
                                        <section className="space-y-2">
                                            <h2 className="text-foreground text-sm font-semibold">Notas</h2>
                                            <ul className="space-y-2">
                                                {detail.group_notes.map((note) => (
                                                    <li
                                                        key={note.id}
                                                        className={`rounded-lg border p-2 text-sm ${
                                                            note.scope === 'kitchen_alert'
                                                                ? 'border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)]'
                                                                : 'border-border bg-muted/40'
                                                        }`}
                                                    >
                                                        <div className="text-muted-foreground mb-0.5 flex items-center gap-1 text-[10px] uppercase">
                                                            {note.scope === 'kitchen_alert' ? (
                                                                <>
                                                                    <ChefHat className="h-3 w-3" /> Cocina
                                                                </>
                                                            ) : (
                                                                'Grupo'
                                                            )}
                                                        </div>
                                                        {note.body}
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    )}
                                </aside>
                            </div>
                        ) : null}
                    </>
                )}
            </div>

            <ConfirmDialog
                open={closeEmptyOpen}
                title="¿Cerrar mesa sin pago?"
                message="Solo permite si no hay platos servidos ni en cocina. Útil cuando el grupo se levantó sin pedir."
                confirmLabel="Cerrar mesa"
                onConfirm={() => void closeEmpty()}
                onCancel={() => setCloseEmptyOpen(false)}
                loading={busy}
            />

            <BottomSheetDialog isOpen={waiterNoteOpen} onClose={() => setWaiterNoteOpen(false)} title="Nueva nota" className="max-w-md">
                <div className="space-y-3 p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant={waiterNoteScope === 'group' ? 'default' : 'outline'}
                            onClick={() => setWaiterNoteScope('group')}
                            className="flex-1 sm:flex-none"
                        >
                            Para la mesa
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={waiterNoteScope === 'kitchen_alert' ? 'default' : 'outline'}
                            onClick={() => setWaiterNoteScope('kitchen_alert')}
                            className="flex-1 sm:flex-none"
                        >
                            Para cocina
                        </Button>
                    </div>
                    <NotesEditor
                        value={waiterNoteBody}
                        onChange={setWaiterNoteBody}
                        label={waiterNoteScope === 'kitchen_alert' ? 'Alerta para cocina' : 'Nota grupal'}
                    />
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="ghost" size="sm" onClick={() => setWaiterNoteOpen(false)}>
                            <X className="mr-1 h-3.5 w-3.5" /> Cancelar
                        </Button>
                        <Button type="button" size="sm" onClick={() => void submitWaiterNote()} disabled={busy || waiterNoteBody.trim() === ''}>
                            <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Guardar
                        </Button>
                    </div>
                </div>
            </BottomSheetDialog>
        </PageShell>
    );
}

function CancelInKitchenInline({ onConfirm, disabled }: { onConfirm: (reason: string) => void; disabled?: boolean }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');

    if (!open) {
        return (
            <Button
                type="button"
                size="sm"
                variant="ghost"
                className="text-destructive hover:text-destructive h-7 px-2 text-xs"
                onClick={() => setOpen(true)}
                disabled={disabled}
            >
                Cancelar este plato
            </Button>
        );
    }

    return (
        <div className="bg-muted/40 space-y-2 rounded-lg p-2">
            <NotesEditor
                value={reason}
                onChange={setReason}
                label="Motivo (obligatorio)"
                placeholder="Ej: se acabó el insumo, cliente cambió de opinión"
            />
            <div className="flex items-center justify-end gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        setOpen(false);
                        setReason('');
                    }}
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    onClick={() => {
                        onConfirm(reason);
                        setOpen(false);
                        setReason('');
                    }}
                    disabled={disabled || reason.trim() === ''}
                >
                    Confirmar
                </Button>
            </div>
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
