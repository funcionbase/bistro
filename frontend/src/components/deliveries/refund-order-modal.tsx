import { RefundSummaryCard } from '@/components/deliveries/refund-summary-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import type { KanbanOrder } from '@/hooks/use-orders';
import { usePaymentMethods, paymentRequiresReference } from '@/hooks/use-payment-methods';
import { apiFetch } from '@/lib/api';
import { shortOrderCode } from '@/lib/order-code';
import { AlertCircle } from 'lucide-react';
import { useState } from 'react';
import { sanitizePlainText } from '@/lib/input-sanitize';

interface RefundOrderModalProps {
    order: KanbanOrder;
    onClose: () => void;
    onConfirmed: () => void;
}

/**
 * Modal de devolución parcial o total. Para card/transfer exige número de
 * comprobante. Extraído de la página de ventas del día para limpiar el
 * archivo principal.
 */
export function RefundOrderModal({ order, onClose, onConfirmed }: RefundOrderModalProps) {
    const formatCurrency = useCurrencyFormatter();
    const catalog = usePaymentMethods();
    const method = order.payment?.method ?? null;
    // Catálogo canónico: nequi/daviplata también exigen referencia, no solo card/transfer.
    const requiresReference = method !== null && paymentRequiresReference(catalog, method);
    // Sin pago registrado no hay nada que devolver: el backend lo rechaza. Se
    // bloquea el confirm y se guía a cancelar, en vez de dejar que falle.
    const hasPayment = !!order.payment;

    const remaining = hasPayment ? (order.refund?.remaining_refundable ?? order.total) : 0;
    const alreadyRefunded = order.refund?.total_refunded_all ?? 0;

    const [mode, setMode] = useState<'full' | 'partial'>('full');
    const [partialAmount, setPartialAmount] = useState<string>('');
    const [reference, setReference] = useState('');
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleConfirm = async () => {
        if (requiresReference && !reference.trim()) {
            setError('Ingresa el número de comprobante de la devolución.');
            return;
        }
        let amount: number | null = null;
        if (mode === 'partial') {
            const parsed = parseFloat(partialAmount);
            if (!Number.isFinite(parsed) || parsed <= 0) {
                setError('Ingresa un monto a devolver mayor a 0.');
                return;
            }
            if (parsed > remaining + 0.001) {
                setError(`El monto excede el remanente (${formatCurrency(remaining)}).`);
                return;
            }
            amount = Math.round(parsed * 100) / 100;
        }

        setSubmitting(true);
        setError(null);
        try {
            const res = await apiFetch(`/api/v1/orders/${order.id}/refund`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    reference: reference.trim() || null,
                    reason: reason.trim() || null,
                    amount,
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError((json as { message?: string }).message ?? 'No se pudo procesar la devolución.');
                return;
            }
            onConfirmed();
        } catch {
            // fetch lanzó (red caída/timeout): el server pudo haber procesado el
            // refund igual — advertir antes de que el cajero reintente a ciegas.
            setError('Error de conexión. Verifica si la devolución se procesó antes de reintentar.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <BottomSheetDialog isOpen={true} onClose={onClose} title={`Devolver orden #${shortOrderCode(order.id)}`}>
            <div className="flex flex-col gap-4 p-1">
                <RefundSummaryCard
                    total={order.total}
                    alreadyRefunded={alreadyRefunded}
                    remaining={remaining}
                    method={method}
                    formatCurrency={formatCurrency}
                />

                <div className="space-y-2">
                    <span className="text-sm font-medium">Tipo de devolución</span>
                    <div className="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            onClick={() => setMode('full')}
                            className={`focus:ring-ring rounded-md border p-2 text-sm transition focus:ring-2 focus:outline-none ${
                                mode === 'full' ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted'
                            }`}
                        >
                            Total ({formatCurrency(remaining)})
                        </button>
                        <button
                            type="button"
                            onClick={() => setMode('partial')}
                            className={`focus:ring-ring rounded-md border p-2 text-sm transition focus:ring-2 focus:outline-none ${
                                mode === 'partial' ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted'
                            }`}
                        >
                            Parcial
                        </button>
                    </div>
                    {mode === 'partial' && (
                        <div className="space-y-1">
                            <Label htmlFor="refund-amount" className="text-xs">
                                Monto a devolver
                            </Label>
                            <Input
                                id="refund-amount"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={remaining}
                                step="1"
                                value={partialAmount}
                                onChange={(e) => setPartialAmount(e.target.value)}
                                placeholder="0"
                            />
                        </div>
                    )}
                </div>

                {requiresReference && (
                    <div className="space-y-1">
                        <Label htmlFor="refund-ref" className="text-sm">
                            Número de comprobante de la devolución
                        </Label>
                        <Input
                            id="refund-ref"
                            value={reference}
                            onChange={(e) => setReference(sanitizePlainText(e.target.value, 120, false, false))}
                            maxLength={120}
                            placeholder="Ej. 000123456"
                        />
                    </div>
                )}

                <div className="space-y-1">
                    <Label htmlFor="refund-reason" className="text-sm">
                        Motivo (opcional)
                    </Label>
                    <Input
                        id="refund-reason"
                        value={reason}
                        onChange={(e) => setReason(sanitizePlainText(e.target.value, 255, true, false))}
                        maxLength={255}
                        placeholder="Ej. plato equivocado"
                    />
                </div>

                {!hasPayment && (
                    <Alert>
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>Esta orden no tiene un pago registrado, no hay nada que devolver. Usá "Cancelar orden" en su lugar.</AlertDescription>
                    </Alert>
                )}

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <div className="flex gap-2">
                    <Button variant="outline" className="flex-1" onClick={onClose} disabled={submitting}>
                        Cerrar
                    </Button>
                    <Button
                        variant="destructive"
                        className="flex-1"
                        onClick={() => void handleConfirm()}
                        disabled={submitting || !hasPayment}
                    >
                        {submitting ? 'Procesando…' : 'Confirmar devolución'}
                    </Button>
                </div>
            </div>
        </BottomSheetDialog>
    );
}
