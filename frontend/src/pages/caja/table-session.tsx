import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CajaTableSessionSkeleton } from '@/components/ui/caja-table-session-skeleton';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { GuestItemList } from '@/components/ui/guest-item-list';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { SplitPaymentSheet } from '@/components/ui/split-payment-sheet';
import { useCashRegister } from '@/hooks/use-cash-register';
import { usePaymentMethods } from '@/hooks/use-payment-methods';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import type { PaymentMethod } from '@/types';

import { AlertCircle, CheckCircle2, Clock, RefreshCw } from 'lucide-react';
import { DetailRow } from '@/components/ui/detail-row';
import { KpiCell } from '@/components/ui/kpi-cell';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';

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
    id: string | null;
    display_name: string;
    phone: string;
    subtotal: string;
    unpaid_amount: string;
    items: ItemForCashier[];
}

interface ReceiptSummary {
    id: string;
    guest_id: string | null;
    payment_method: string;
    amount: string;
    reference: string | null;
    paid_at: string | null;
    client_uuid: string | null;
}

interface OrderSummary {
    id: string;
    status: string;
    total: string;
    ordered_at: string | null;
}

interface TimelineEvent {
    at: string;
    action: string;
    label: string;
    detail: string | null;
    order_id: string | null;
    duration_seconds: number | null;
}

interface CashierState {
    order: { id: string; status: string; total: string; tip_amount: string } | null;
    session: { id: string; status: string; table_number: string | null; order_type: string | null };
    orders: OrderSummary[];
    guests: GuestBreakdown[];
    unpaid_total: string;
    paid_total: string;
    tip_total: string;
    receipts: ReceiptSummary[];
}

/**
 * Caja para una sesión de mesa con QR (#191 Fase 6).
 *
 * Desglose por comensal con selección de items para pago parcial, o pago
 * total con un solo receipt. Refund por item con motivo. Receipts
 * inmutables — refund es siempre receipt nuevo con amount negativo.
 */
export default function CajaTableSession() {
    const id = window.location.pathname.split('/').pop() ?? '';
    const navigate = useNavigate();
    const token = useToken();
    const { selectedRegister } = useCashRegister(token);
    const { auth } = useSharedData();
    const cashierName = auth.user.first_name
        ? `${auth.user.first_name} ${auth.user.last_name ?? ''}`.trim()
        : auth.user.name;

    const [state, setState] = useState<CashierState | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [timeline, setTimeline] = useState<TimelineEvent[] | null>(null);

    // Selección por comensal para pago parcial.
    const [selectedByGuest, setSelectedByGuest] = useState<Record<string, Record<string, boolean>>>({});
    const [paySheet, setPaySheet] = useState<
        { kind: 'partial'; guestId: string | null; itemIds: string[]; amount: string } | { kind: 'all'; amount: string } | null
    >(null);

    const [refundItem, setRefundItem] = useState<{
        itemId: string;
        guestId: string;
        amount: string;
        name: string;
    } | null>(null);
    const [refundMethod, setRefundMethod] = useState<PaymentMethod>('cash');
    const [refundReference, setRefundReference] = useState('');
    const paymentCatalog = usePaymentMethods();

    const fetchState = useCallback(async () => {
        try {
            const resp = await apiFetch(`/api/v1/caja/table-sessions/${id}`);
            if (!resp.ok) throw new Error('No pudimos cargar la sesión.');
            const json = (await resp.json()) as { data: CashierState };
            setState(json.data);
            setError(null);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setLoading(false);
        }
    }, [id]);

    useEffect(() => {
        void fetchState();
    }, [fetchState]);

    useEffect(() => {
        apiFetch(`/api/v1/caja/table-sessions/${id}/timeline`)
            .then((r) => (r.ok ? r.json() : null))
            .then((json: { data?: TimelineEvent[] } | null) => {
                if (json?.data) setTimeline(json.data);
            })
            .catch(() => null);
    }, [id]);


    const toggleItem = (guestId: string, itemId: string) => {
        setSelectedByGuest((prev) => {
            const cur = prev[guestId] ?? {};
            return { ...prev, [guestId]: { ...cur, [itemId]: !cur[itemId] } };
        });
    };

    const selectAllUnpaid = (guest: GuestBreakdown) => {
        setSelectedByGuest((prev) => ({
            ...prev,
            [guest.id ?? 'ungrouped']: Object.fromEntries(guest.items.filter((i) => !i.paid_at).map((i) => [i.id, true])),
        }));
    };

    const openPayPartial = (guest: GuestBreakdown) => {
        const key = guest.id ?? 'ungrouped';
        const selected = selectedByGuest[key] ?? {};
        const itemIds = guest.items.filter((i) => !i.paid_at && selected[i.id]).map((i) => i.id);

        if (itemIds.length === 0) {
            setActionError('Selecciona al menos un item para cobrar.');
            return;
        }

        const subtotal = guest.items.filter((i) => itemIds.includes(i.id)).reduce((acc, i) => acc + Number.parseFloat(i.subtotal), 0);

        setPaySheet({
            kind: 'partial',
            guestId: guest.id,
            itemIds,
            amount: subtotal.toFixed(2),
        });
    };

    const openPayAll = () => {
        if (!state) return;
        setPaySheet({ kind: 'all', amount: state.unpaid_total });
    };

    const submitPayment = async (input: { payment_method: PaymentMethod; amount: string; reference: string | null; tip_amount: number }) => {
        if (!paySheet) return;
        setBusy(true);
        setActionError(null);
        try {
            const clientUuid = crypto.randomUUID();
            const body: Record<string, unknown> = {
                payment_method: input.payment_method,
                amount: input.amount,
                reference: input.reference,
                tip_amount: input.tip_amount,
                client_uuid: clientUuid,
            };
            let url: string;
            if (paySheet.kind === 'partial') {
                url = `/api/v1/caja/table-sessions/${id}/pay-partial`;
                body.guest_id = paySheet.guestId;
                body.item_ids = paySheet.itemIds;
            } else {
                url = `/api/v1/caja/table-sessions/${id}/pay-all`;
            }
            const resp = await apiFetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Cobro rechazado.');
            }
            await fetchState();
            setSelectedByGuest({});
            setPaySheet(null);
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error en el cobro.');
        } finally {
            setBusy(false);
        }
    };

    const submitRefund = async () => {
        if (!refundItem) return;
        if (refundReference.trim() === '') {
            setActionError('La referencia del refund es obligatoria.');
            return;
        }
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/caja/table-sessions/${id}/refund-item`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    item_id: refundItem.itemId,
                    payment_method: refundMethod,
                    amount: refundItem.amount,
                    reference: refundReference.trim(),
                    client_uuid: crypto.randomUUID(),
                }),
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Refund rechazado.');
            }
            await fetchState();
            setRefundItem(null);
            setRefundReference('');
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error en el refund.');
        } finally {
            setBusy(false);
        }
    };

    const sessionClosed = state?.session.status === 'closed';

    const guestForRefund = useMemo(() => {
        if (!refundItem || !state) return null;
        return state.guests.find((g) => g.id === refundItem.guestId) ?? null;
    }, [refundItem, state]);

    void guestForRefund;

    const tableLabel = (() => {
        if (!state) return 'Mesa';
        if (state.session.table_number) return `Mesa #${state.session.table_number}`;
        if (state.session.order_type === 'delivery') return 'Domicilio';
        if (state.session.order_type === 'pickup') return 'Para llevar';
        return 'Mesa';
    })();

    return (
        <PageShell title={`Cobro ${tableLabel}`}>
            <div className="flex min-h-0 flex-1 flex-col gap-6 p-4 pb-6 sm:p-6">
                {loading ? (
                    <CajaTableSessionSkeleton />
                ) : (
                    <>
                        <PageHeader
                            eyebrow={selectedRegister?.name ?? 'Caja'}
                            title={`Cobro ${tableLabel}`}
                            description={buildDescription(state, cashierName)}
                            actions={
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => navigate('/orders/cashier')}
                                        className="w-full sm:w-auto"
                                    >
                                        Volver a caja
                                    </Button>
                                    {!sessionClosed && (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => void fetchState()}
                                            disabled={loading || busy}
                                            className="w-full sm:w-auto"
                                        >
                                            <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Refrescar
                                        </Button>
                                    )}
                                    {!sessionClosed && state && Number.parseFloat(state.unpaid_total) > 0 && (
                                        <Button type="button" size="sm" onClick={openPayAll} disabled={busy} className="w-full sm:w-auto">
                                            Cobrar toda la mesa
                                        </Button>
                                    )}
                                </div>
                            }
                        />

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

                        {sessionClosed && state && (
                            <Alert>
                                <CheckCircle2 className="h-4 w-4" />
                                <AlertDescription>Mesa cerrada. Todos los items han sido cobrados.</AlertDescription>
                            </Alert>
                        )}

                        {!sessionClosed && state && Number.parseFloat(state.unpaid_total) > 0 &&
                            state.orders.some((o) => o.status === 'completed') && (() => {
                                const n = state.orders.filter((o) => o.status === 'completed').length;
                                return (
                                    <Alert variant="destructive">
                                        <AlertCircle className="h-4 w-4" />
                                        <AlertDescription>
                                            {n === 1 ? 'Un pedido fue' : `${n} pedidos fueron`} marcado{n === 1 ? '' : 's'} como completado{n === 1 ? '' : 's'} en el tablero sin pago registrado. <strong>Cobra por aquí antes de liberar la mesa.</strong>
                                        </AlertDescription>
                                    </Alert>
                                );
                            })()}

                        {sessionClosed && state && (
                            <ClosedOrderSummary
                                state={state}
                                cashierName={cashierName}
                                paymentLabels={paymentCatalog.labels}
                            />
                        )}

                        {state && state.guests.length === 0 && !sessionClosed && (
                            <Alert>
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>Esta sesión no tiene pedidos activos aún.</AlertDescription>
                            </Alert>
                        )}

                        {state && state.guests.length > 0 ? (
                            <div className="grid w-full items-start gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(100%,360px),1fr))]">
                                {state.guests.map((guest) => (
                                    <GuestItemList
                                        key={guest.id ?? 'ungrouped'}
                                        guest={guest}
                                        selected={selectedByGuest[guest.id ?? 'ungrouped'] ?? {}}
                                        onToggle={(itemId) => toggleItem(guest.id ?? 'ungrouped', itemId)}
                                        onSelectAllUnpaid={() => selectAllUnpaid(guest)}
                                        onPay={() => openPayPartial(guest)}
                                        onRefundItem={(itemId) => {
                                            const item = guest.items.find((i) => i.id === itemId);
                                            if (!item) return;
                                            setRefundItem({
                                                itemId,
                                                guestId: guest.id ?? '',
                                                amount: item.subtotal,
                                                name: item.name,
                                            });
                                            setRefundMethod('cash');
                                            setRefundReference('');
                                        }}
                                        readOnly={sessionClosed}
                                    />
                                ))}
                            </div>
                        ) : null}

                        {state && state.receipts.length > 0 && (
                            <section className="space-y-2">
                                <h2 className="text-foreground text-sm font-semibold">Comprobantes emitidos</h2>
                                <div className="border-border bg-card divide-y rounded-2xl border">
                                    {state.receipts.map((r) => (
                                        <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant="secondary"
                                                    className={
                                                        Number.parseFloat(r.amount) < 0
                                                            ? 'border-transparent bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]'
                                                            : 'border-transparent bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe)]'
                                                    }
                                                >
                                                    {r.payment_method}
                                                </Badge>
                                                <span className="tabular-nums">{formatCurrency(Number.parseFloat(r.amount))}</span>
                                                {r.reference && <span className="text-muted-foreground text-xs">ref: {r.reference}</span>}
                                            </div>
                                            <span className="text-muted-foreground text-xs">
                                                {r.paid_at ? new Date(r.paid_at).toLocaleString('es-CO') : ''}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        )}

                        {timeline && timeline.length > 0 && (
                            <OrderTimeline events={timeline} />
                        )}
                    </>
                )}
            </div>

            <SplitPaymentSheet
                open={!!paySheet}
                onClose={() => setPaySheet(null)}
                title={paySheet?.kind === 'all' ? 'Cobrar toda la mesa' : 'Cobrar comensal'}
                amount={paySheet?.amount ?? '0.00'}
                onSubmit={submitPayment}
                loading={busy}
            />

            <ConfirmDialog
                open={!!refundItem}
                title={`Devolver "${refundItem?.name ?? ''}"`}
                message={`Se creará un comprobante de devolución por ${refundItem ? formatCurrency(Number.parseFloat(refundItem.amount)) : ''}. El item quedará como cancelado. Esta acción no es reversible.`}
                confirmLabel="Devolver"
                onConfirm={() => void submitRefund()}
                onCancel={() => setRefundItem(null)}
                loading={busy}
            />

            {refundItem && (
                <div className="fixed inset-x-0 bottom-0 z-40 mx-auto max-w-md space-y-2 p-4">
                    <div className="bg-card border-border rounded-2xl border p-3 shadow-lg">
                        <p className="text-foreground mb-2 text-xs font-semibold">Detalles del refund</p>
                        <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${paymentCatalog.methods.length}, minmax(0, 1fr))` }}>
                            {paymentCatalog.methods.map((m) => (
                                <Button
                                    key={m}
                                    type="button"
                                    size="sm"
                                    variant={refundMethod === m ? 'default' : 'outline'}
                                    onClick={() => setRefundMethod(m)}
                                >
                                    {paymentCatalog.labels[m]}
                                </Button>
                            ))}
                        </div>
                        <div className="mt-2">
                            <Label htmlFor="refund-ref" className="text-xs">
                                Referencia comprobante de devolución (obligatorio)
                            </Label>
                            <Input
                                id="refund-ref"
                                value={refundReference}
                                onChange={(e) => setRefundReference(e.target.value)}
                                placeholder="Ej: voucher reverso 8732"
                                maxLength={120}
                                className="mt-1"
                            />
                        </div>
                    </div>
                </div>
            )}
        </PageShell>
    );
}

function ClosedOrderSummary({
    state,
    cashierName,
    paymentLabels,
}: {
    state: CashierState;
    cashierName: string;
    paymentLabels: Record<string, string>;
}) {
    const totalItems = state.guests.reduce((acc, g) => acc + g.items.length, 0);
    const totalFact = Number.parseFloat(state.order?.total ?? '0');
    const paid = Number.parseFloat(state.paid_total);
    const tip = Number.parseFloat(state.tip_total);

    // Desglose por método: solo cobros positivos.
    const byMethod = state.receipts.reduce<Record<string, number>>((acc, r) => {
        const amt = Number.parseFloat(r.amount);
        if (amt > 0) acc[r.payment_method] = (acc[r.payment_method] ?? 0) + amt;
        return acc;
    }, {});
    const refundTotal = state.receipts.reduce((acc, r) => {
        const amt = Number.parseFloat(r.amount);
        return amt < 0 ? acc + Math.abs(amt) : acc;
    }, 0);

    const orderedAt = state.orders[0]?.ordered_at ?? null;
    const closedAt = state.receipts.reduce<string | null>((latest, r) => {
        if (!r.paid_at) return latest;
        if (!latest) return r.paid_at;
        return r.paid_at > latest ? r.paid_at : latest;
    }, null);

    const fmt = (d: string) =>
        new Date(d).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' });

    return (
        <section className="border-border bg-card space-y-4 rounded-2xl border p-4 shadow-sm">
            <h2 className="text-foreground text-sm font-semibold">Resumen de cobro</h2>

            {/* KPIs */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <KpiCell label="Total facturado" value={formatCurrency(totalFact)} />
                <KpiCell label="Total cobrado" value={formatCurrency(paid)} />
                {tip > 0 && <KpiCell label="Propina" value={formatCurrency(tip)} />}
                <KpiCell label="Items" value={totalItems} />
            </div>

            {/* Desglose por método */}
            {Object.keys(byMethod).length > 0 && (
                <div className="border-border border-t pt-3">
                    <p className="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
                        Desglose por método
                    </p>
                    <div className="grid grid-cols-2 gap-x-6 gap-y-1.5 sm:grid-cols-3">
                        {Object.entries(byMethod).map(([method, amount]) => (
                            <DetailRow
                                key={method}
                                label={paymentLabels[method] ?? method}
                                value={formatCurrency(amount)}
                                valueClassName="text-sm font-semibold tabular-nums"
                            />
                        ))}
                        {refundTotal > 0 && (
                            <DetailRow
                                label="Devoluciones"
                                value={`-${formatCurrency(refundTotal)}`}
                                valueClassName="text-sm font-semibold tabular-nums text-[color:var(--color-status-critical)]"
                            />
                        )}
                    </div>
                </div>
            )}

            {/* Metadata */}
            <div className="border-border grid grid-cols-2 gap-x-6 gap-y-1.5 border-t pt-3 sm:grid-cols-3">
                {orderedAt && (
                    <DetailRow label="Pedido el" value={fmt(orderedAt)} valueClassName="text-sm" />
                )}
                {closedAt && (
                    <DetailRow label="Cerrado el" value={fmt(closedAt)} valueClassName="text-sm" />
                )}
                <DetailRow label="Cajero" value={cashierName} valueClassName="text-sm font-medium" />
                <DetailRow label="Comprobantes" value={state.receipts.length} valueClassName="text-sm tabular-nums" />
                {state.orders.map((o) => (
                    <DetailRow
                        key={o.id}
                        label="Orden"
                        value={<span className="font-mono text-xs">{o.id.slice(-8)}</span>}
                    />
                ))}
            </div>
        </section>
    );
}

function buildDescription(state: CashierState | null, cashierName: string): string {
    if (!state) return 'Cargando…';
    if (!state.order) return `Sin pedidos · ${cashierName}`;
    const unpaid = Number.parseFloat(state.unpaid_total);
    const paid = Number.parseFloat(state.paid_total);
    const tip = Number.parseFloat(state.tip_total);
    const parts: string[] = [];
    if (unpaid > 0) parts.push(`Pendiente: ${formatCurrency(unpaid)}`);
    if (paid > 0) parts.push(`Cobrado: ${formatCurrency(paid)}`);
    if (tip > 0) parts.push(`Propina: ${formatCurrency(tip)}`);
    parts.push(`Cobró: ${cashierName}`);
    return parts.join(' · ');
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
}

function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    if (seconds < 3600) return s > 0 ? `${m}m ${s}s` : `${m}m`;
    const h = Math.floor(m / 60);
    const rm = m % 60;
    return rm > 0 ? `${h}h ${rm}m` : `${h}h`;
}

const ACTION_META: Record<string, { dot: string; text: string }> = {
    'session.opened': { dot: 'bg-[color:var(--color-status-info)]', text: 'text-[color:var(--color-status-info)]' },
    'session.closed': { dot: 'bg-[color:var(--color-status-safe)]', text: 'text-[color:var(--color-status-safe)]' },
    'order.created': { dot: 'bg-foreground', text: 'text-foreground' },
    'kds.in_kitchen': { dot: 'bg-[color:var(--color-status-warning)]', text: 'text-[color:var(--color-status-warning)]' },
    'kds.ready': { dot: 'bg-[color:var(--color-status-safe)]', text: 'text-[color:var(--color-status-safe)]' },
    'payment.split': { dot: 'bg-[color:var(--color-status-info)]', text: 'text-[color:var(--color-status-info)]' },
    'order.status.completed': { dot: 'bg-[color:var(--color-status-safe)]', text: 'text-[color:var(--color-status-safe)]' },
    'order.status.cancelled': { dot: 'bg-[color:var(--color-status-critical)]', text: 'text-[color:var(--color-status-critical)]' },
    'order.status.refunded': { dot: 'bg-[color:var(--color-status-critical)]', text: 'text-[color:var(--color-status-critical)]' },
};

function defaultMeta() {
    return { dot: 'bg-muted-foreground', text: 'text-muted-foreground' };
}

function OrderTimeline({ events }: { events: TimelineEvent[] }) {
    const fmtTime = (iso: string) =>
        new Date(iso).toLocaleTimeString('es-CO', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            timeZone: 'America/Bogota',
        });

    const openedEvent = events.find((e) => e.action === 'session.opened');
    const closedEvent = events.find((e) => e.action === 'session.closed');
    // First order placed (earliest order.created across all orders in session)
    const firstOrderEvent = events.filter((e) => e.action === 'order.created').sort((a, b) => a.at.localeCompare(b.at))[0];
    // First kds.ready = food started arriving
    const firstReadyEvent = events.filter((e) => e.action === 'kds.ready').sort((a, b) => a.at.localeCompare(b.at))[0];
    // First payment = comensal started paying
    const firstPaymentEvent = events.filter((e) => e.action === 'payment.split').sort((a, b) => a.at.localeCompare(b.at))[0];

    const totalSeconds = openedEvent
        ? Math.floor(((closedEvent ? new Date(closedEvent.at) : new Date()).getTime() - new Date(openedEvent.at).getTime()) / 1000)
        : null;

    // Tiempo esperando el pedido: desde que ordenó hasta que llegó el primer plato
    const waitSeconds =
        firstOrderEvent && firstReadyEvent
            ? Math.floor((new Date(firstReadyEvent.at).getTime() - new Date(firstOrderEvent.at).getTime()) / 1000)
            : null;

    // Tiempo de consumo: desde que llegó el primer plato hasta el primer pago (o cierre)
    const consumeEnd = firstPaymentEvent ?? closedEvent;
    const consumeSeconds =
        firstReadyEvent && consumeEnd
            ? Math.floor((new Date(consumeEnd.at).getTime() - new Date(firstReadyEvent.at).getTime()) / 1000)
            : null;

    return (
        <section className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-foreground flex items-center gap-1.5 text-sm font-semibold">
                    <Clock className="h-4 w-4" />
                    Historial de la mesa
                </h2>
                <div className="flex flex-wrap items-center gap-1.5">
                    {waitSeconds !== null && waitSeconds > 0 && (
                        <span className="text-muted-foreground bg-muted rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums" title="Tiempo desde que ordenó hasta que llegó el primer plato">
                            esperó {formatDuration(waitSeconds)}
                        </span>
                    )}
                    {consumeSeconds !== null && consumeSeconds > 0 && (
                        <span className="text-muted-foreground bg-muted rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums" title="Tiempo desde que llegó el pedido hasta el primer pago">
                            comió {formatDuration(consumeSeconds)}
                        </span>
                    )}
                    {totalSeconds !== null && (
                        <span className="text-muted-foreground bg-muted rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums">
                            {closedEvent ? '' : '⏱ '}
                            {formatDuration(totalSeconds)} en mesa
                        </span>
                    )}
                </div>
            </div>
            <div className="border-border bg-card rounded-2xl border px-4 py-3">
                <ol className="relative space-y-0">
                    {events.map((ev, i) => {
                        const meta = ACTION_META[ev.action] ?? defaultMeta();
                        const isLast = i === events.length - 1;
                        return (
                            <li key={`${ev.action}-${ev.at}-${i}`} className="relative flex gap-3 pb-4 last:pb-0">
                                {/* línea vertical */}
                                {!isLast && (
                                    <div className="border-border absolute top-3 left-[7px] h-full border-l border-dashed" />
                                )}
                                {/* dot */}
                                <div className={`relative mt-1 h-3.5 w-3.5 shrink-0 rounded-full ${meta.dot} ring-background ring-2`} />
                                {/* contenido */}
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <span className={`text-sm font-medium ${meta.text}`}>{ev.label}</span>
                                        {ev.duration_seconds !== null && ev.duration_seconds > 0 && (
                                            <span className="text-muted-foreground bg-muted rounded px-1.5 py-0.5 text-[11px] tabular-nums">
                                                +{formatDuration(ev.duration_seconds)}
                                            </span>
                                        )}
                                    </div>
                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                        <span className="text-muted-foreground font-mono text-xs">{fmtTime(ev.at)}</span>
                                        {ev.detail && <span className="text-muted-foreground text-xs">{ev.detail}</span>}
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            </div>
        </section>
    );
}
