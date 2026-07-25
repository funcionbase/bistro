import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ReasonTooltip } from '@/components/ui/field-hint';
import type { ChatCartFlow, ChatCartFlowOrder } from '@/hooks/use-chats';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { ArrowLeftRight, CheckCircle2, Receipt, XCircle } from 'lucide-react';
import { useState } from 'react';

interface Props {
    cartFlow: ChatCartFlow;
    /** chats.update — enviar recibo / rechazar comprobante / reenviar carta. */
    canUpdate: boolean;
    /** orders.update — aprobar pedido / cambiar tipo. */
    canApprove: boolean;
    onSendReceipt: (order: ChatCartFlowOrder) => Promise<void>;
    onRejectProof: (order: ChatCartFlowOrder) => Promise<void>;
    onApprove: (order: ChatCartFlowOrder) => Promise<void>;
    /** Cambio de tipo en caliente (F5). `address` requerido al pasar a domicilio. */
    onChangeOrderType: (order: ChatCartFlowOrder, to: 'pickup' | 'delivery', address?: string) => Promise<void>;
    onResendMenuLink: () => Promise<void>;
    onOpenOrder: (orderId: string) => void;
}

const TRANSFER_PREFS = new Set(['transfer', 'nequi', 'daviplata']);

const PAYMENT_LABELS: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    nequi: 'Nequi',
    daviplata: 'Daviplata',
};

const TERMINAL_STATUSES = new Set(['completed', 'failed', 'cancelled', 'refunded', 'abandoned']);

/**
 * Panel de próxima acción del flujo de carta (F4): estado del link enviado
 * (abrió / está armando el pedido / venció) y, por cada orden en curso, las
 * acciones del cajero — enviar recibo térmico, aprobar, rechazar comprobante.
 * Nada se envía automático: todo es un botón que presiona el operador.
 */
export function ChatCartActions({
    cartFlow,
    canUpdate,
    canApprove,
    onSendReceipt,
    onRejectProof,
    onApprove,
    onChangeOrderType,
    onResendMenuLink,
    onOpenOrder,
}: Props) {
    const formatCurrency = useCurrencyFormatter();
    const [busy, setBusy] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    // Cambio a domicilio: pide la dirección inline antes de confirmar.
    const [addressFor, setAddressFor] = useState<string | null>(null);
    const [addressDraft, setAddressDraft] = useState('');

    const run = async (key: string, fn: () => Promise<void>) => {
        if (busy) return;
        setBusy(key);
        setActionError(null);
        try {
            await fn();
        } catch (e) {
            setActionError(e instanceof Error ? e.message : 'La acción falló. Intenta de nuevo.');
        } finally {
            setBusy(null);
        }
    };

    const { session } = cartFlow;
    const activeOrders = cartFlow.orders.filter((o) => !TERMINAL_STATUSES.has(o.status));
    const expired = session.expired_at !== null && new Date(session.expired_at).getTime() < Date.now();
    const building =
        session.last_activity_at !== null && Date.now() - new Date(session.last_activity_at).getTime() < 3 * 60_000;

    // Estado del link cuando aún no hay órdenes en curso.
    let sessionLabel: string | null = null;
    if (activeOrders.length === 0) {
        if (expired && session.status !== 'converted') sessionLabel = 'Link de la carta vencido';
        else if (building) sessionLabel = 'Está armando el pedido…';
        else if (session.viewed_at !== null)
            sessionLabel = `Abrió la carta ${new Date(session.viewed_at).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })}`;
        else sessionLabel = 'Carta enviada — sin abrir';
    }

    if (activeOrders.length === 0 && sessionLabel === null) return null;

    return (
        <div className="border-b px-3 py-2">
            {sessionLabel !== null && (
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    <span
                        className={
                            building
                                ? 'flex items-center gap-1.5 font-medium text-[color:var(--color-status-warning)]'
                                : 'text-muted-foreground'
                        }
                    >
                        {building && <span className="h-2 w-2 animate-pulse rounded-full bg-[color:var(--color-status-warning)]" aria-hidden />}
                        {sessionLabel}
                    </span>
                    {expired && session.status !== 'converted' && canUpdate && (
                        <Button size="sm" variant="outline" className="h-7 text-xs" disabled={busy !== null} onClick={() => void run('resend', onResendMenuLink)}>
                            Reenviar carta
                        </Button>
                    )}
                </div>
            )}

            {activeOrders.map((order) => {
                const isPending = order.status === 'pending_approval';
                const isTransfer = TRANSFER_PREFS.has(order.payment_preference ?? '');
                const totalToPay = order.total + order.tip_amount;
                const change = order.cash_pays_with !== null ? order.cash_pays_with - totalToPay : null;
                const receiptCurrent = order.receipt_sent_at !== null && !order.receipt_stale;
                const showReceipt = isPending || order.receipt_stale;

                return (
                    <div key={order.id} className="py-1.5">
                        <div className="flex flex-wrap items-center gap-1.5 text-xs">
                            <button
                                type="button"
                                className="hover:text-primary font-semibold underline-offset-2 hover:underline"
                                onClick={() => onOpenOrder(order.id)}
                                title="Ver detalle del pedido"
                            >
                                Pedido #{order.short_code}
                            </button>
                            <Badge variant="outline" className="text-[10px]">
                                {order.status_label}
                            </Badge>
                            <span className="text-muted-foreground">{order.order_type === 'delivery' ? 'Domicilio' : 'Para llevar'}</span>
                            {order.payment_preference && (
                                <Badge variant="secondary" className="text-[10px]">
                                    {PAYMENT_LABELS[order.payment_preference] ?? order.payment_preference}
                                </Badge>
                            )}
                            <span className="font-medium tabular-nums">{formatCurrency(totalToPay)}</span>
                            {order.cash_pays_with !== null && (
                                <span className="rounded bg-[color:var(--color-status-warning)]/15 px-1.5 py-0.5 font-medium text-[color:var(--color-status-warning)] tabular-nums">
                                    Paga con {formatCurrency(order.cash_pays_with)}
                                    {change !== null && change >= 0 ? ` · devueltas ${formatCurrency(change)}` : ''}
                                </span>
                            )}
                        </div>
                        {order.customer_notes && (
                            <p className="mt-1 rounded bg-[color:var(--color-status-warning)]/10 px-1.5 py-0.5 text-xs">
                                📝 {order.customer_notes}
                            </p>
                        )}
                        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                            {showReceipt && (
                                <ReasonTooltip reason={!canUpdate ? 'Necesitás el permiso «Editar chats» para enviar el recibo.' : null}>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-7 text-xs"
                                        disabled={!canUpdate || busy !== null}
                                        onClick={() => void run(`receipt-${order.id}`, () => onSendReceipt(order))}
                                    >
                                        <Receipt className="h-3.5 w-3.5" />
                                        {order.receipt_stale
                                            ? 'Reenviar recibo — total cambió'
                                            : order.receipt_sent_at
                                              ? 'Reenviar recibo'
                                              : 'Enviar recibo'}
                                    </Button>
                                </ReasonTooltip>
                            )}
                            {isPending && receiptCurrent && (
                                <span className="text-muted-foreground text-[11px]">Recibo enviado ✓</span>
                            )}
                            {isPending && (
                                <ReasonTooltip
                                    reason={
                                        !canApprove
                                            ? 'Necesitás el permiso «Editar pedidos» para aprobar.'
                                            : isTransfer
                                              ? '¿Verificaste el comprobante de la transferencia?'
                                              : null
                                    }
                                >
                                    <Button
                                        size="sm"
                                        className="h-7 text-xs"
                                        disabled={!canApprove || busy !== null}
                                        onClick={() => void run(`approve-${order.id}`, () => onApprove(order))}
                                    >
                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                        Aprobar pedido
                                    </Button>
                                </ReasonTooltip>
                            )}
                            {isPending && isTransfer && (
                                <ReasonTooltip reason={!canUpdate ? 'Necesitás el permiso «Editar chats».' : null}>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="h-7 text-xs text-[color:var(--color-status-critical)]"
                                        disabled={!canUpdate || busy !== null}
                                        onClick={() => void run(`reject-${order.id}`, () => onRejectProof(order))}
                                    >
                                        <XCircle className="h-3.5 w-3.5" />
                                        Rechazar comprobante
                                    </Button>
                                </ReasonTooltip>
                            )}
                            {/* Cambio de tipo en caliente (F5): pickup↔delivery, nunca in_transit. */}
                            {['pickup', 'delivery'].includes(order.order_type) && order.status !== 'in_transit' && canApprove && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-7 text-xs"
                                    disabled={busy !== null}
                                    onClick={() => {
                                        if (order.order_type === 'delivery') {
                                            void run(`type-${order.id}`, () => onChangeOrderType(order, 'pickup'));
                                        } else {
                                            setAddressFor(addressFor === order.id ? null : order.id);
                                            setAddressDraft(order.delivery_address ?? '');
                                        }
                                    }}
                                >
                                    <ArrowLeftRight className="h-3.5 w-3.5" />
                                    {order.order_type === 'delivery' ? 'Pasar a para llevar' : 'Pasar a domicilio'}
                                </Button>
                            )}
                        </div>
                        {addressFor === order.id && (
                            <div className="mt-1.5 flex items-center gap-1.5">
                                <Input
                                    value={addressDraft}
                                    onChange={(e) => setAddressDraft(sanitizePlainText(e.target.value, 500, true, false))}
                                    maxLength={500}
                                    placeholder="Dirección de entrega"
                                    className="h-7 text-xs"
                                />
                                <Button
                                    size="sm"
                                    className="h-7 text-xs"
                                    disabled={busy !== null || addressDraft.trim().length < 5}
                                    onClick={() =>
                                        void run(`type-${order.id}`, async () => {
                                            await onChangeOrderType(order, 'delivery', addressDraft.trim());
                                            setAddressFor(null);
                                        })
                                    }
                                >
                                    Confirmar
                                </Button>
                            </div>
                        )}
                    </div>
                );
            })}

            {actionError && <p className="text-destructive mt-1 text-xs">{actionError}</p>}
        </div>
    );
}
