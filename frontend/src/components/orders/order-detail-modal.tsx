import { DianOrderActions } from '@/components/dian/dian-order-actions';
import OrderNotesSection from '@/components/orders/order-notes-section';
import { OrderStatusBadge } from '@/components/orders/order-status-badge';
import { Badge } from '@/components/ui/badge';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import type { KanbanOrder, KanbanOrderLineItem, KanbanOrderNote, KanbanRelatedOrder } from '@/hooks/use-orders';
import { usePaymentMethods } from '@/hooks/use-payment-methods';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { shortOrderCode } from '@/lib/order-code';
import { getOrderTypeMeta } from '@/lib/order-type';
import type { PaymentMethod } from '@/types';
import { ExternalLink, Link2, MapPin, MessageCircle, Package, Phone, Store, Tag, Truck, UserCheck, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';

interface OrderDetailModalProps {
    order: KanbanOrder | null;
    isOpen: boolean;
    onClose: () => void;
    /** Si se provee y la orden está `ready` sin repartidor, muestra botón "Asignar repartidor". */
    onAssignCourier?: (orderId: string) => void;
    /** Si se provee y la orden tiene repartidor con delivery `pending`, muestra botón "Reasignar". */
    onReassignCourier?: (orderId: string, deliveryId: string) => void;
    /** Avanza el estado de la orden (forward-only). Habilita botones de cambio de
     *  estado dentro del detalle — clave en mobile, donde no hay drag-and-drop. */
    onAdvanceStatus?: (orderId: string, newStatus: string) => void;
    /** Estados a los que la orden puede avanzar (forward-only), calculados por el
     *  tablero a partir de `kanban_rank`. */
    advanceOptions?: { key: string; label: string }[];
    /** Cancela una orden no pagada. Bloqueado en estados terminales o con pago registrado. */
    onCancelOrder?: (orderId: string) => void;
    /** Devuelve una orden pagada. Si el método fue card/transfer pide comprobante de la devolución. */
    onRefundOrder?: (orderId: string) => void;
    /** Info del pago registrado (si existe). Permite al modal mostrar método y referencia. */
    payment?: {
        method: PaymentMethod | null;
        reference: string | null;
    } | null;
    /** Info de la devolución (si la orden está reembolsada). */
    refund?: {
        original_method: PaymentMethod | null;
        total_refunded: number | null;
        reference: string | null;
        is_partial?: boolean;
        total_refunded_all?: number;
        remaining_refundable?: number;
    } | null;
}

export function OrderDetailModal({
    order,
    isOpen,
    onClose,
    onAssignCourier,
    onReassignCourier,
    onAdvanceStatus,
    advanceOptions,
    onCancelOrder,
    onRefundOrder,
    payment,
    refund,
}: OrderDetailModalProps) {
    const navigate = useNavigate();
    const formatCurrency = useCurrencyFormatter();
    const token = useToken();
    const orderStatuses = useOrderStatuses();
    const paymentCatalog = usePaymentMethods();

    // El listado kanban no trae notas ni line_items por costo (consulta
    // liviana). Cuando el modal se abre con una orden sin esos datos, los
    // pedimos al endpoint de detalle. Si el listado ya los trae, no re-fetch.
    const [fetchedNotes, setFetchedNotes] = useState<KanbanOrderNote[] | null>(null);
    const [fetchedLineItems, setFetchedLineItems] = useState<KanbanOrderLineItem[] | null>(null);
    const [fetchedRelated, setFetchedRelated] = useState<KanbanRelatedOrder[] | null>(null);
    const [fetchedStatus, setFetchedStatus] = useState<string | null>(null);
    useEffect(() => {
        if (!isOpen || !order) {
            setFetchedNotes(null);
            setFetchedLineItems(null);
            setFetchedRelated(null);
            setFetchedStatus(null);
            return;
        }
        if (order.notes && order.line_items && order.related_orders) {
            return;
        }
        let cancelled = false;
        apiFetch(`/api/v1/orders/${order.id}`)
            .then((res) => (res.ok ? res.json() : null))
            .then(
                (
                    json: { data?: { status?: string; notes?: KanbanOrderNote[]; line_items?: KanbanOrderLineItem[]; related_orders?: KanbanRelatedOrder[] } } | null,
                ) => {
                    if (cancelled || !json?.data) return;
                    if (json.data.status) setFetchedStatus(json.data.status);
                    if (json.data.notes) setFetchedNotes(json.data.notes);
                    if (json.data.line_items) setFetchedLineItems(json.data.line_items);
                    if (json.data.related_orders) setFetchedRelated(json.data.related_orders);
                },
            )
            .catch(() => {
                // Silent: si falla, simplemente no se muestran datos extra.
            });
        return () => {
            cancelled = true;
        };
    }, [isOpen, order]);

    const effectiveNotes = order?.notes ?? fetchedNotes ?? null;
    const effectiveRelated = order?.related_orders ?? fetchedRelated ?? null;
    const effectiveStatus = fetchedStatus ?? order?.status ?? '';
    // Preferimos line_items (filas order_items) sobre items legacy JSON.
    // Para órdenes de mesa con QR, items JSON está null y la verdad vive en
    // order_items. Para delivery/pickup legacy, line_items se construye desde
    // items en el backend, así que siempre tenemos data normalizada acá.
    const effectiveItems: KanbanOrderLineItem[] =
        order?.line_items ??
        fetchedLineItems ??
        (order?.items ?? []).map((i) => ({
            id: i.id,
            menu_item_id: i.id,
            name: i.name,
            quantity: i.quantity,
            unit_price: i.price,
            notes: i.notes ?? null,
            status: null,
            cancellation_reason: null,
            guest_label: null,
        }));

    const goToChat = () => {
        if (!order?.chat_id) return;
        const params = new URLSearchParams();
        params.set('chat', String(order.chat_id));
        if (token) params.set('token', token);
        navigate(`/chats?${params.toString()}`);
    };

    const goToFullDetail = () => {
        if (!order) return;
        navigate(`/orders/${order.id}`);
        onClose();
    };

    const formattedDate = order?.ordered_at
        ? new Intl.DateTimeFormat('es-CO', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'America/Bogota',
          }).format(new Date(order.ordered_at))
        : null;

    const subtotal = order ? order.total + order.discount_amount : 0;
    const typeMeta = getOrderTypeMeta(order?.order_type);
    const TypeIcon = typeMeta?.icon ?? Store;

    // Una orden "en sitio" (mesa) nunca lleva repartidor. Inferimos tipo mesa por
    // order_type explícito o, en legacy sin order_type, por la presencia de número
    // de mesa. Solo las de domicilio (o legacy con objeto delivery) muestran courier.
    const isTableOrder = order?.order_type === 'table' || (!order?.order_type && !!order?.table_number);
    const showCourierSection = !!order && !isTableOrder && (order.order_type === 'delivery' || !!order.delivery);
    const isTerminalStatus = !!order && ['completed', 'cancelled', 'refunded', 'abandoned', 'failed'].includes(effectiveStatus);

    return (
        <BottomSheetDialog isOpen={isOpen} onClose={onClose} title={`Orden #${shortOrderCode(order?.id)}`}>
            {order ? (
                <div className="space-y-4">
                    {/* Tipo de orden y estado */}
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2 text-sm">
                            <TypeIcon className="text-muted-foreground h-4 w-4" />
                            <span className="font-medium">{typeMeta?.label ?? 'Orden'}</span>
                            {order.table_number && (
                                <Badge variant="outline" className="text-xs">
                                    Mesa {order.table_number}
                                </Badge>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <OrderStatusBadge status={effectiveStatus} />
                            <Button type="button" size="sm" variant="outline" onClick={goToFullDetail}>
                                <ExternalLink className="mr-1.5 h-3 w-3" />
                                Ver detalle
                            </Button>
                        </div>
                    </div>

                    {/* Cambiar estado: forward-only. Imprescindible en mobile, donde el
                        tablero no permite drag-and-drop para avanzar la orden. */}
                    {onAdvanceStatus && !isTerminalStatus && advanceOptions && advanceOptions.length > 0 && (
                        <div className="space-y-1.5">
                            <p className="text-muted-foreground text-xs font-medium">Cambiar estado</p>
                            <div className="flex flex-wrap gap-2">
                                {advanceOptions.map((opt) => (
                                    <Button
                                        key={opt.key}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => onAdvanceStatus(order.id, opt.key)}
                                        data-testid={`advance-status-${opt.key}`}
                                    >
                                        {opt.label}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Dirección de domicilio */}
                    {order.delivery_address && (
                        <div className="flex items-start gap-2 text-sm">
                            <MapPin className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0" />
                            <span>{order.delivery_address}</span>
                        </div>
                    )}

                    {/* Teléfono cliente + chat: no aplica cuando la orden es En sitio (cliente presente) */}
                    {order.order_type !== 'table' && order.client_phone && (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Phone className="text-muted-foreground h-4 w-4" />
                            <span>{order.client_phone}</span>
                            {order.chat_id && (
                                <Button type="button" size="sm" variant="outline" className="ml-auto" onClick={goToChat}>
                                    <MessageCircle className="mr-1.5 h-3 w-3" />
                                    Ir a chat
                                </Button>
                            )}
                        </div>
                    )}

                    {formattedDate && <p className="text-muted-foreground text-xs">{formattedDate}</p>}

                    <Separator />

                    {/* Ítems */}
                    <div>
                        <div className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                            <Package className="h-4 w-4" />
                            <span>Ítems ({effectiveItems.length})</span>
                        </div>
                        {effectiveItems.length === 0 ? (
                            <p className="text-muted-foreground text-xs italic">Cargando ítems…</p>
                        ) : (
                            <ul className="space-y-2">
                                {effectiveItems.map((item, i) => (
                                    <li key={item.id ?? i} className="flex items-start justify-between gap-2 text-sm">
                                        <div className="min-w-0 flex-1">
                                            <div>
                                                <span className="font-medium">{item.quantity}×</span> <span>{item.name}</span>
                                                {item.status === 'cancelled' && (
                                                    <span className="ml-2 text-xs font-medium text-[color:var(--color-status-critical)]">
                                                        · cancelado
                                                    </span>
                                                )}
                                            </div>
                                            {item.guest_label && <p className="text-muted-foreground text-xs">{item.guest_label}</p>}
                                            {item.notes && (
                                                <p className="bg-muted/50 mt-1 rounded px-2 py-1 text-xs italic">
                                                    <span className="text-muted-foreground not-italic">Nota: </span>
                                                    {item.notes}
                                                </p>
                                            )}
                                        </div>
                                        <span className="shrink-0 tabular-nums">{formatCurrency(item.unit_price * item.quantity)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {effectiveNotes && effectiveNotes.length > 0 && (
                        <>
                            <Separator />
                            <OrderNotesSection notes={effectiveNotes} compact />
                        </>
                    )}

                    {effectiveRelated && effectiveRelated.length > 0 && (
                        <>
                            <Separator />
                            <div>
                                <div className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                                    <Link2 className="h-4 w-4" />
                                    <span>Otros pedidos de esta mesa</span>
                                    <span className="text-muted-foreground text-xs font-normal">· {effectiveRelated.length}</span>
                                </div>
                                <p className="text-muted-foreground mb-2 text-xs">
                                    Esta orden hace parte de una sesión con varios pedidos. Toca uno para ver su detalle.
                                </p>
                                <ul className="space-y-1.5">
                                    {effectiveRelated.map((rel) => (
                                        <li key={rel.id}>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    // Reabrir el modal con la orden relacionada.
                                                    // Construimos un esqueleto KanbanOrder mínimo;
                                                    // el useEffect del modal hará el fetch completo.
                                                    onClose();
                                                    // setTimeout para que el modal complete su
                                                    // unmount antes de reabrirse con la nueva orden.
                                                    setTimeout(() => {
                                                        window.dispatchEvent(
                                                            new CustomEvent('open-order-detail', {
                                                                detail: { orderId: rel.id },
                                                            }),
                                                        );
                                                    }, 50);
                                                }}
                                                className="border-border bg-muted/30 hover:bg-muted/50 focus:ring-ring flex w-full items-center justify-between gap-2 rounded-lg border p-2 text-left text-sm transition-colors focus:ring-2 focus:outline-none"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <span className="text-muted-foreground font-mono text-xs">#{rel.id}</span>
                                                    {rel.ordered_at && (
                                                        <span className="text-muted-foreground text-xs">
                                                            {new Date(rel.ordered_at).toLocaleTimeString('es-CO', {
                                                                hour: '2-digit',
                                                                minute: '2-digit',
                                                                timeZone: 'America/Bogota',
                                                            })}
                                                        </span>
                                                    )}
                                                    <Badge variant="secondary" className="text-[10px]">
                                                        {rel.status}
                                                    </Badge>
                                                </div>
                                                <span className="text-foreground font-medium tabular-nums">{formatCurrency(rel.total)}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </>
                    )}

                    <Separator />

                    {/* Totales */}
                    <div className="space-y-1 text-sm">
                        {order.discount_amount > 0 && (
                            <>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Subtotal</span>
                                    <span className="tabular-nums">{formatCurrency(subtotal)}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground flex items-center gap-1">
                                        <Tag className="h-3 w-3" />
                                        {order.coupon_code ? `Cupón ${order.coupon_code}` : 'Descuento'}
                                    </span>
                                    <span className="text-[color:var(--color-status-safe)] tabular-nums">
                                        − {formatCurrency(order.discount_amount)}
                                    </span>
                                </div>
                            </>
                        )}
                        {(order.tax_amount ?? 0) > 0 && (
                            <>
                                <div className="text-muted-foreground flex justify-between text-xs">
                                    <span>Subtotal (base gravable)</span>
                                    <span className="tabular-nums">{formatCurrency(order.subtotal ?? 0)}</span>
                                </div>
                                <div className="text-muted-foreground flex justify-between text-xs">
                                    <span>Impuesto ({(order.tax_rate ?? 0).toFixed(2)}%)</span>
                                    <span className="tabular-nums">{formatCurrency(order.tax_amount ?? 0)}</span>
                                </div>
                            </>
                        )}
                        <div className="flex justify-between font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">{formatCurrency(order.total)}</span>
                        </div>
                        {(order.tip_amount ?? 0) > 0 && (
                            <div className="flex justify-between text-xs text-[color:var(--color-status-warning)]">
                                <span>+ Propina (no contabilizada como ingreso)</span>
                                <span className="tabular-nums">{formatCurrency(order.tip_amount ?? 0)}</span>
                            </div>
                        )}
                    </div>

                    {/* Repartidor asignado y controles de asignación/reasignación.
                        Nunca para órdenes de mesa (en sitio): no se reparten. */}
                    {showCourierSection && (
                        <>
                            <Separator />
                            <div className="space-y-2 text-sm">
                                <div className="flex items-center gap-2">
                                    <Truck className="text-muted-foreground h-4 w-4" />
                                    <span className="text-muted-foreground">Repartidor:</span>
                                    <span className="font-medium">{order.delivery?.deliverer?.name ?? 'Sin asignar'}</span>
                                </div>
                                {order.delivery?.status && (
                                    <p className="text-muted-foreground pl-6 text-xs">Estado de entrega: {order.delivery.status}</p>
                                )}

                                {/* Asignar repartidor: orden ready o in_transit sin delivery pending */}
                                {onAssignCourier && !order.delivery && ['ready', 'in_transit'].includes(effectiveStatus) && (
                                    <div className="pt-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() => onAssignCourier(order.id)}
                                            data-testid="assign-courier-from-detail"
                                        >
                                            <UserPlus className="mr-2 h-4 w-4" />
                                            Asignar repartidor
                                        </Button>
                                    </div>
                                )}

                                {/* Reasignar repartidor: orden con delivery pendiente y aún operativa */}
                                {onReassignCourier &&
                                    order.delivery &&
                                    order.delivery.status === 'pending' &&
                                    orderStatuses.operational.includes(effectiveStatus as never) && (
                                        <div className="pt-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => onReassignCourier(order.id, order.delivery!.id)}
                                                data-testid="reassign-courier-from-detail"
                                            >
                                                <UserCheck className="mr-2 h-4 w-4" />
                                                Reasignar repartidor
                                            </Button>
                                        </div>
                                    )}
                            </div>
                        </>
                    )}

                    {/* Información de pago / devolución (cuando la orden ya pasó por caja) */}
                    {(payment || refund) && (
                        <>
                            <Separator />
                            <div className="space-y-1 text-sm">
                                {payment && (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-muted-foreground">Pago:</span>
                                        <Badge variant="outline">{payment.method ? paymentCatalog.labels[payment.method] : '—'}</Badge>
                                        {payment.reference && <span className="text-muted-foreground text-xs">Comp. #{payment.reference}</span>}
                                    </div>
                                )}
                                {refund && (
                                    <div className="space-y-0.5 text-[color:var(--color-status-warning)]">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span>Última devolución:</span>
                                            <span className="font-semibold tabular-nums">{formatCurrency(refund.total_refunded ?? 0)}</span>
                                            {refund.reference && <span className="text-xs">Comp. devolución #{refund.reference}</span>}
                                        </div>
                                        {refund.is_partial && (
                                            <div className="text-xs">
                                                Acumulado devuelto:{' '}
                                                <span className="tabular-nums">{formatCurrency(refund.total_refunded_all ?? 0)}</span> · Remanente:{' '}
                                                <span className="tabular-nums">{formatCurrency(refund.remaining_refundable ?? 0)}</span>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </>
                    )}

                    {/* Facturación DIAN: documentos electrónicos ligados a la
                        orden. El componente carga vía /api/v1/dian/electronic-
                        documents?order_id=X y muestra badges + acciones según
                        permisos (read/emit/retry/credit_note/print). Si la
                        orden no es facturable o el usuario no tiene
                        dian.documents.read, no renderiza nada. */}
                    <Separator />
                    <div className="space-y-2">
                        <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">Facturación DIAN</p>
                        <DianOrderActions orderId={order.id} orderStatus={effectiveStatus} />
                    </div>

                    {/* Acciones de cancelación / devolución */}
                    {(onCancelOrder || onRefundOrder) && !['cancelled', 'refunded', 'abandoned'].includes(effectiveStatus) && (
                        <>
                            <Separator />
                            <div className="flex flex-wrap gap-2">
                                {onRefundOrder && payment && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="destructive"
                                        onClick={() => onRefundOrder(order.id)}
                                        data-testid="refund-order"
                                    >
                                        Devolver
                                    </Button>
                                )}
                                {onCancelOrder && !payment && effectiveStatus !== 'completed' && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="destructive"
                                        onClick={() => onCancelOrder(order.id)}
                                        data-testid="cancel-order"
                                    >
                                        Cancelar pedido
                                    </Button>
                                )}
                            </div>
                            {effectiveStatus === 'completed' && !payment && (
                                <p className="text-muted-foreground text-xs">Una orden completada no puede cancelarse.</p>
                            )}
                        </>
                    )}
                </div>
            ) : null}
        </BottomSheetDialog>
    );
}
