import { PaymentMethodPicker } from '@/components/orders/payment-method-picker';
import { TipPicker } from '@/components/orders/tip-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { useHasPlanFeature } from '@/hooks/use-plan-feature';
import type { PaymentMethod, TableOrder } from '@/hooks/use-tables';
import type { DianRecipientLookup } from '@/types/dian';
import { AlertCircle, CheckCircle2, FileText } from 'lucide-react';

interface PaymentState {
    open: boolean;
    orderId: string | null;
    method: PaymentMethod;
    amountReceived: string;
    reference: string;
    tipAmount: string;
    submitting: boolean;
    error: string | null;
    // HU #235 — campos DIAN (defaults vacíos cuando el usuario no tiene
    // permiso o no marca el toggle).
    dianRequested: boolean;
    dianPrint: boolean;
    dianClientPhone: string;
    dianLookup: DianRecipientLookup | null;
    dianLookupLoading: boolean;
    dianLookupError: string | null;
    dianEmissionError: string | null;
}

interface TablePaymentSheetProps {
    paymentState: PaymentState;
    /** Orden seleccionada (fuente de los totales). */
    selectedOrder: TableOrder | null;
    /** URL del QR de pagos de la empresa, o null si no hay configurado. */
    companyQrUrl: string | null;
    tipParsed: number;
    expectedTotal: number;
    cashChange: number | null;
    formatCurrency: (value: number) => string;
    setPaymentState: React.Dispatch<React.SetStateAction<PaymentState>>;
    onClose: () => void;
    onSubmit: () => void;
    /** Dispara el lookup explícito por teléfono (botón "Buscar cliente"). */
    onLookupDianClient?: () => void;
    /**
     * Abre el modal "Nuevo contacto" (mismo de /clients) cuando el lookup no
     * encuentra al cliente. La página padre gestiona el modal.
     */
    onRegisterClient?: () => void;
    /**
     * Callback que abre el `RecipientNeedsDataDialog` con el contactId del
     * lookup parcial. La página padre ya tiene el modal y lo gestiona; el
     * sheet solo emite la intención.
     */
    onRequestRecipientDataCompletion?: (contactId: string) => void;
}

/**
 * Sheet de "cerrar y cobrar" una orden de mesa: propina voluntaria, método
 * de pago, monto recibido / devuelta y comprobante.
 *
 * HU #235: sección DIAN colapsable visible solo cuando el usuario tiene
 * `dian.documents.emit`. Toggle "Cliente solicita factura DIAN" + checkbox
 * "Imprimir tirilla DIAN" + lookup por teléfono. La emisión la dispara
 * `useTablePayment.submitPayment` tras el cobro exitoso.
 */
export function TablePaymentSheet({
    paymentState,
    selectedOrder,
    companyQrUrl,
    tipParsed,
    expectedTotal,
    cashChange,
    formatCurrency,
    setPaymentState,
    onClose,
    onSubmit,
    onLookupDianClient,
    onRegisterClient,
    onRequestRecipientDataCompletion,
}: TablePaymentSheetProps) {
    const { has } = usePermissions();
    const hasDianFeature = useHasPlanFeature('dian');
    const canEmitDian = has('dian.documents.emit') && hasDianFeature;
    const canPrintDian = has('dian.print');
    const canLookupRecipients = has('dian.recipients.read');

    return (
        <BottomSheetDialog isOpen={paymentState.open} onClose={onClose} title="Cerrar y cobrar" className="max-w-lg">
            {selectedOrder && (
                <div className="flex max-h-[75vh] flex-col">
                    <div className="flex flex-1 flex-col gap-4 overflow-y-auto p-4">
                        <div className="bg-muted/40 space-y-1 rounded-md border px-3 py-2 text-sm">
                            <div className="flex items-center justify-between">
                                <span>Total orden</span>
                                <span className="font-semibold tabular-nums">{formatCurrency(selectedOrder.total)}</span>
                            </div>
                            {tipParsed > 0 && (
                                <div className="text-muted-foreground flex items-center justify-between">
                                    <span>+ Propina</span>
                                    <span className="tabular-nums">{formatCurrency(tipParsed)}</span>
                                </div>
                            )}
                            <div className="flex items-center justify-between border-t pt-1">
                                <span className="font-semibold">Total a cobrar</span>
                                <span className="text-lg font-semibold tabular-nums">{formatCurrency(expectedTotal)}</span>
                            </div>
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="tip-amount" className="text-sm">
                                Propina (opcional)
                            </Label>
                            <Input
                                id="tip-amount"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                step="1"
                                value={paymentState.tipAmount}
                                onChange={(e) =>
                                    setPaymentState((p) => ({
                                        ...p,
                                        tipAmount: e.target.value,
                                        error: null,
                                    }))
                                }
                                placeholder="0"
                            />
                            <p className="text-muted-foreground text-xs">
                                Voluntaria. No paga impuesto y no entra como ingreso de la empresa.
                            </p>
                            <TipPicker
                                baseAmount={selectedOrder.total}
                                value={paymentState.tipAmount}
                                onChange={(v) => setPaymentState((p) => ({ ...p, tipAmount: v, error: null }))}
                                formatCurrency={formatCurrency}
                                disabled={paymentState.submitting}
                                className="pt-1"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label className="text-sm">Método de pago</Label>
                            <PaymentMethodPicker
                                value={paymentState.method}
                                onChange={(method) => setPaymentState((p) => ({ ...p, method, error: null }))}
                                disabled={paymentState.submitting}
                            />
                        </div>

                        {paymentState.method === 'cash' && (
                            <div className="space-y-2">
                                <Label htmlFor="amount-received" className="text-sm">
                                    Monto recibido
                                </Label>
                                <Input
                                    id="amount-received"
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    step="1"
                                    value={paymentState.amountReceived}
                                    onChange={(e) =>
                                        setPaymentState((p) => ({
                                            ...p,
                                            amountReceived: e.target.value,
                                            error: null,
                                        }))
                                    }
                                    placeholder="0"
                                />
                                <div className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                                    <span className="text-muted-foreground">Devuelta</span>
                                    <span className="font-semibold tabular-nums">
                                        {cashChange === null ? '—' : formatCurrency(cashChange)}
                                    </span>
                                </div>
                            </div>
                        )}

                        {paymentState.method === 'card' && (
                            <div className="space-y-2">
                                <Label htmlFor="reference" className="text-sm">
                                    Número de comprobante (recibo)
                                </Label>
                                <Input
                                    id="reference"
                                    value={paymentState.reference}
                                    onChange={(e) => setPaymentState((p) => ({ ...p, reference: e.target.value }))}
                                    placeholder="Ej. 000123456"
                                    maxLength={120}
                                />
                                <p className="text-muted-foreground text-xs">Ingresa el número impreso en el comprobante del datáfono.</p>
                            </div>
                        )}

                        {paymentState.method === 'transfer' && (
                            <div className="space-y-2">
                                {companyQrUrl ? (
                                    <div className="flex flex-col items-center gap-2 rounded-md border p-3">
                                        <img src={companyQrUrl} alt="QR de pago" className="h-48 w-48 rounded object-contain" />
                                        <span className="text-muted-foreground text-sm">Pídele al cliente que escanee el código</span>
                                    </div>
                                ) : (
                                    <Alert variant="warning">
                                        <AlertCircle className="h-4 w-4" />
                                        <AlertDescription>
                                            No hay QR configurado. Súbelo en{' '}
                                            <a href="/company/settings" className="underline">
                                                Mi Empresa › Información
                                            </a>
                                            .
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <Label htmlFor="transfer-ref" className="text-sm">
                                    Número de comprobante (transferencia)
                                </Label>
                                <Input
                                    id="transfer-ref"
                                    value={paymentState.reference}
                                    onChange={(e) => setPaymentState((p) => ({ ...p, reference: e.target.value }))}
                                    placeholder="Ej. 000123456"
                                    maxLength={120}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Ingresa el número del comprobante mostrado por el cliente tras realizar la transferencia.
                                </p>
                            </div>
                        )}

                        {/* #facturación-dian — con permiso RBAC pero sin Plan Plus, se
                            informa en vez de ocultar en silencio. */}
                        {has('dian.documents.emit') && !hasDianFeature && (
                            <p className="text-muted-foreground rounded-md border border-dashed p-3 text-xs italic">
                                Facturación DIAN: opción no incluida en tu plan actual.
                            </p>
                        )}

                        {/* HU #235 — sección DIAN. Visible solo con permiso emit + Plan Plus. */}
                        {canEmitDian && (
                            <div className="space-y-2 rounded-md border border-dashed p-3">
                                <div className="flex items-center gap-2">
                                    <FileText className="text-muted-foreground h-4 w-4" />
                                    <span className="text-sm font-medium">Facturación DIAN</span>
                                </div>

                                <label className="flex cursor-pointer items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={paymentState.dianRequested}
                                        onCheckedChange={(checked) =>
                                            setPaymentState((p) => ({
                                                ...p,
                                                dianRequested: Boolean(checked),
                                                dianEmissionError: null,
                                            }))
                                        }
                                        disabled={paymentState.submitting}
                                    />
                                    Cliente solicita factura DIAN
                                </label>

                                {canPrintDian && (
                                    <label className="flex cursor-pointer items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={paymentState.dianPrint}
                                            onCheckedChange={(checked) =>
                                                setPaymentState((p) => ({ ...p, dianPrint: Boolean(checked) }))
                                            }
                                            disabled={paymentState.submitting || !paymentState.dianRequested}
                                        />
                                        Imprimir tirilla DIAN al cobrar
                                    </label>
                                )}

                                {paymentState.dianRequested && canLookupRecipients && (
                                    <div className="space-y-2 pt-1">
                                        <Label htmlFor="dian-phone" className="text-xs">
                                            Teléfono del contacto (lookup)
                                        </Label>
                                        <div className="flex gap-2">
                                            <Input
                                                id="dian-phone"
                                                value={paymentState.dianClientPhone}
                                                onChange={(e) =>
                                                    setPaymentState((p) => ({
                                                        ...p,
                                                        dianClientPhone: e.target.value,
                                                        dianLookup: null,
                                                        dianLookupError: null,
                                                    }))
                                                }
                                                placeholder="+573001234567"
                                                maxLength={30}
                                                disabled={paymentState.submitting || paymentState.dianLookupLoading}
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={onLookupDianClient}
                                                disabled={
                                                    paymentState.submitting ||
                                                    paymentState.dianLookupLoading ||
                                                    !paymentState.dianClientPhone.trim()
                                                }
                                            >
                                                {paymentState.dianLookupLoading ? 'Buscando…' : 'Buscar'}
                                            </Button>
                                        </div>

                                        {paymentState.dianLookupError && (
                                            <Alert variant="destructive" className="p-2">
                                                <AlertDescription className="text-xs">
                                                    {paymentState.dianLookupError}
                                                </AlertDescription>
                                            </Alert>
                                        )}

                                        {paymentState.dianLookup && paymentState.dianLookup.data.length === 0 && (
                                            <Alert>
                                                <AlertDescription className="flex flex-col items-start gap-2 text-xs">
                                                    <span>Contacto no encontrado. Se emitirá DEE POS al consumidor final genérico.</span>
                                                    {onRegisterClient && (
                                                        <Button type="button" variant="outline" size="sm" onClick={onRegisterClient}>
                                                            Registrar cliente
                                                        </Button>
                                                    )}
                                                </AlertDescription>
                                            </Alert>
                                        )}

                                        {paymentState.dianLookup && paymentState.dianLookup.data.length > 1 && (
                                            <Alert variant="warning">
                                                <AlertDescription className="text-xs">
                                                    Hay {paymentState.dianLookup.data.length} contactos con ese teléfono (familiares). Pedile al
                                                    cliente su número de documento y buscalo por documento para emitir FEV al correcto.
                                                </AlertDescription>
                                            </Alert>
                                        )}

                                        {paymentState.dianLookup?.data.length === 1 && paymentState.dianLookup.data[0].dian_complete && (
                                            <Alert variant="safe">
                                                <AlertDescription className="text-xs">
                                                    Contacto identificado:{' '}
                                                    <strong>
                                                        {paymentState.dianLookup.data[0].legal_name ?? paymentState.dianLookup.data[0].name}
                                                    </strong>
                                                    . Se emitirá Factura Electrónica de Venta (FEV).
                                                </AlertDescription>
                                            </Alert>
                                        )}

                                        {paymentState.dianLookup?.data.length === 1 && !paymentState.dianLookup.data[0].dian_complete && (
                                            <Alert variant="warning">
                                                <AlertDescription className="flex flex-col items-start gap-2 text-xs">
                                                    <span>
                                                        Contacto encontrado pero le faltan datos fiscales. Completalos para emitir FEV.
                                                    </span>
                                                    {onRequestRecipientDataCompletion && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                onRequestRecipientDataCompletion(paymentState.dianLookup!.data[0].id)
                                                            }
                                                        >
                                                            Completar datos del contacto
                                                        </Button>
                                                    )}
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                    </div>
                                )}

                                {paymentState.dianEmissionError && (
                                    <Alert variant="destructive" className="p-2">
                                        <AlertDescription className="text-xs">
                                            El cobro se registró, pero la emisión DIAN falló: {paymentState.dianEmissionError}. Reinténtalo
                                            desde el detalle de la orden.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="bg-background flex flex-col gap-2 border-t p-4">
                        {paymentState.error && (
                            <Alert variant="destructive" className="p-2 [&>svg]:top-2 [&>svg]:left-2 [&>svg~*]:pl-5">
                                <AlertCircle className="h-3.5 w-3.5" />
                                <AlertDescription className="text-xs">{paymentState.error}</AlertDescription>
                            </Alert>
                        )}
                        <div className="flex gap-2">
                            <Button variant="outline" className="flex-1" onClick={onClose} disabled={paymentState.submitting}>
                                Cancelar
                            </Button>
                            <Button className="flex-1" onClick={onSubmit} disabled={paymentState.submitting}>
                                <CheckCircle2 className="mr-2 h-4 w-4" />
                                {paymentState.submitting ? 'Cobrando…' : 'Confirmar pago'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </BottomSheetDialog>
    );
}
