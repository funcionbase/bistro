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
import { usePaymentMethods } from '@/hooks/use-payment-methods';
import { apiFetch } from '@/lib/api';
import type { PaymentMethod } from '@/types';

import { AlertCircle, CheckCircle2, RefreshCw } from 'lucide-react';
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
    id: string;
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

interface CashierState {
    order: { id: string; status: string; total: string; tip_amount: string } | null;
    session: { id: string; status: string };
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

    const [state, setState] = useState<CashierState | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    // Selección por comensal para pago parcial.
    const [selectedByGuest, setSelectedByGuest] = useState<Record<string, Record<string, boolean>>>({});
    const [paySheet, setPaySheet] = useState<
        { kind: 'partial'; guestId: string; itemIds: string[]; amount: string } | { kind: 'all'; amount: string } | null
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

    // Auto-redirect cuando la sesión se cierra (tras pagar todo).
    useEffect(() => {
        if (state?.session.status === 'closed') {
            const t = setTimeout(() => navigate('/orders/cashier'), 1500);
            return () => clearTimeout(t);
        }
        return undefined;
    }, [state?.session.status, navigate]);

    const toggleItem = (guestId: string, itemId: string) => {
        setSelectedByGuest((prev) => {
            const cur = prev[guestId] ?? {};
            return { ...prev, [guestId]: { ...cur, [itemId]: !cur[itemId] } };
        });
    };

    const selectAllUnpaid = (guest: GuestBreakdown) => {
        setSelectedByGuest((prev) => ({
            ...prev,
            [guest.id]: Object.fromEntries(guest.items.filter((i) => !i.paid_at).map((i) => [i.id, true])),
        }));
    };

    const openPayPartial = (guest: GuestBreakdown) => {
        const selected = selectedByGuest[guest.id] ?? {};
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

    return (
        <PageShell title={`Cobro mesa #${id}`}>
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {loading ? (
                    <CajaTableSessionSkeleton />
                ) : (
                    <>
                        <PageHeader
                            eyebrow="Caja"
                            title={`Cobro de mesa #${id}`}
                            description={
                                state?.order
                                    ? `Saldo pendiente: ${formatCurrency(Number.parseFloat(state.unpaid_total))} · Cobrado: ${formatCurrency(Number.parseFloat(state.paid_total))}`
                                    : 'Cargando…'
                            }
                            actions={
                                <div className="flex flex-wrap items-center gap-2">
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

                        {sessionClosed && (
                            <Alert>
                                <CheckCircle2 className="h-4 w-4" />
                                <AlertDescription>La mesa está cerrada. Volviendo al panel de caja…</AlertDescription>
                            </Alert>
                        )}

                        {state ? (
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                {state.guests.map((guest) => (
                                    <GuestItemList
                                        key={guest.id}
                                        guest={guest}
                                        selected={selectedByGuest[guest.id] ?? {}}
                                        onToggle={(itemId) => toggleItem(guest.id, itemId)}
                                        onSelectAllUnpaid={() => selectAllUnpaid(guest)}
                                        onPay={() => openPayPartial(guest)}
                                        onRefundItem={(itemId) => {
                                            const item = guest.items.find((i) => i.id === itemId);
                                            if (!item) return;
                                            setRefundItem({
                                                itemId,
                                                guestId: guest.id,
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

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
}
