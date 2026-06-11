import { OrderStatusBadge } from '@/components/orders/order-status-badge';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import type { TableOrder } from '@/hooks/use-tables';
import { CheckCircle2, Plus } from 'lucide-react';

interface TableDetailSheetProps {
    /** Orden seleccionada; null mantiene el sheet cerrado. */
    order: TableOrder | null;
    formatCurrency: (value: number) => string;
    onClose: () => void;
    onAddItems: (order: TableOrder) => void;
    onCharge: (order: TableOrder) => void;
}

/**
 * Sheet con el detalle de una orden de mesa abierta: ítems, desglose
 * tributario y acciones para agregar productos o cerrar y cobrar.
 * Extraído de la página de mesas — comportamiento idéntico.
 */
export function TableDetailSheet({ order, formatCurrency, onClose, onAddItems, onCharge }: TableDetailSheetProps) {
    return (
        <BottomSheetDialog
            isOpen={!!order}
            onClose={onClose}
            title={order ? `Mesa ${order.table_number}` : ''}
            className="max-w-lg"
        >
            {order && (
                <div className="flex max-h-[75vh] flex-col">
                    <div className="flex flex-1 flex-col gap-3 overflow-y-auto p-4">
                        <div className="flex items-center justify-between">
                            <OrderStatusBadge status={order.status} />
                            <span className="text-muted-foreground text-xs tabular-nums">
                                {order.ordered_at
                                    ? new Date(order.ordered_at).toLocaleTimeString('es-CO', {
                                          hour: '2-digit',
                                          minute: '2-digit',
                                          timeZone: 'America/Bogota',
                                      })
                                    : '—'}
                            </span>
                        </div>

                        <Card className="rounded-lg shadow-sm">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Ítems</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-sm">
                                {order.items.length === 0 && <p className="text-muted-foreground text-sm">Sin ítems registrados.</p>}
                                {order.items.map((it, idx) => (
                                    <div key={`${it.id}-${idx}`} className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="font-medium">
                                                {it.quantity}× {it.name}
                                            </div>
                                            {it.notes && <div className="text-muted-foreground text-xs">{it.notes}</div>}
                                        </div>
                                        <div className="font-medium tabular-nums">{formatCurrency(it.price * it.quantity)}</div>
                                    </div>
                                ))}
                                <Separator className="my-1" />
                                {order.tax_amount !== undefined && order.tax_amount > 0 && (
                                    <>
                                        <div className="text-muted-foreground flex items-center justify-between text-xs">
                                            <span>Subtotal</span>
                                            <span className="tabular-nums">{formatCurrency(order.subtotal ?? 0)}</span>
                                        </div>
                                        <div className="text-muted-foreground flex items-center justify-between text-xs">
                                            <span>Impuesto ({(order.tax_rate ?? 0).toFixed(0)}%)</span>
                                            <span className="tabular-nums">{formatCurrency(order.tax_amount)}</span>
                                        </div>
                                    </>
                                )}
                                <div className="flex items-center justify-between font-semibold">
                                    <span>Total</span>
                                    <span className="tabular-nums">{formatCurrency(order.total)}</span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <div className="bg-background flex flex-col gap-2 border-t p-4 sm:flex-row">
                        <Button variant="outline" onClick={onClose} className="sm:flex-1">
                            Cerrar
                        </Button>
                        <Button onClick={() => onAddItems(order)} className="sm:flex-1">
                            <Plus className="mr-2 h-4 w-4" /> Agregar productos
                        </Button>
                        <Button variant="default" onClick={() => onCharge(order)} className="sm:flex-1">
                            <CheckCircle2 className="mr-2 h-4 w-4" />
                            Cerrar y cobrar
                        </Button>
                    </div>
                </div>
            )}
        </BottomSheetDialog>
    );
}
