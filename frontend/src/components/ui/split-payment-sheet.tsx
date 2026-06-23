import { BottomSheet } from '@/components/ui/bottom-sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { paymentRequiresReference, usePaymentMethods } from '@/hooks/use-payment-methods';
import { cn } from '@/lib/utils';
import type { PaymentMethod } from '@/types';
import { Banknote, CreditCard, type LucideIcon, Send, Smartphone } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface SplitPaymentSheetProps {
    open: boolean;
    onClose: () => void;
    /** Título del sheet — e.g. "Cobrar a María" o "Cobrar toda la mesa". */
    title: string;
    /** Monto exacto a cobrar (decimal:2 string del backend). */
    amount: string;
    /** Si true, se muestra campo de propina opcional. */
    showTip?: boolean;
    /** Callback con los datos del cobro. El caller maneja idempotency
     *  generando `client_uuid` por intento. */
    onSubmit: (input: {
        payment_method: PaymentMethod;
        amount: string;
        reference: string | null;
        tip_amount: number;
    }) => Promise<void> | void;
    /** Indicador de loading. */
    loading?: boolean;
    className?: string;
}

const METHOD_ICONS: Record<PaymentMethod, LucideIcon> = {
    cash: Banknote,
    card: CreditCard,
    transfer: Send,
    nequi: Smartphone,
    daviplata: Smartphone,
};

/**
 * Modal grande para cobrar a un comensal específico o a toda la mesa
 * (#191 Fase 6).
 *
 * Inputs: método (cash/card/transfer), referencia (obligatoria si no es
 * cash), propina opcional. Valida monto = `amount` exacto y entrega el
 * payload al caller para POST a `/pay-partial` o `/pay-all`. El backend
 * vuelve a validar y crea el PaymentReceipt inmutable.
 *
 * Importante (CLAUDE.md): la propina (tip_amount) no suma a total ni
 * genera impuesto — se guarda en columna separada de orders.tip_amount.
 */
export function SplitPaymentSheet({
    open,
    onClose,
    title,
    amount,
    showTip = true,
    onSubmit,
    loading = false,
    className,
}: SplitPaymentSheetProps) {
    const catalog = usePaymentMethods();
    const [method, setMethod] = useState<PaymentMethod>('cash');
    const [reference, setReference] = useState('');
    const [tipInput, setTipInput] = useState('');
    const [cashReceivedInput, setCashReceivedInput] = useState('');

    const tip = useMemo(() => {
        const parsed = Number.parseFloat(tipInput.replace(',', '.'));
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    }, [tipInput]);

    const totalToCharge = Number.parseFloat(amount) + tip;

    // Efectivo recibido (solo cash): el monto del receipt sigue siendo `amount`
    // exacto — esto es ayuda al cajero para calcular la devuelta, no altera la
    // contabilidad. El backend valida el monto contra el saldo real.
    const cashReceived = useMemo(() => {
        const parsed = Number.parseFloat(cashReceivedInput.replace(',', '.'));
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    }, [cashReceivedInput]);
    const change = cashReceived > 0 ? cashReceived - totalToCharge : 0;
    const insufficientCash = method === 'cash' && cashReceived > 0 && change < 0;

    const refRequired = paymentRequiresReference(catalog, method);
    const refValid = !refRequired || reference.trim() !== '';

    // Al cerrar el sheet limpiamos los campos para que el próximo cobro no
    // arrastre el efectivo/propina/referencia del anterior.
    useEffect(() => {
        if (!open) {
            setCashReceivedInput('');
            setTipInput('');
            setReference('');
        }
    }, [open]);

    const handleSubmit = async () => {
        if (!refValid) return;
        await onSubmit({
            payment_method: method,
            amount,
            reference: reference.trim() === '' ? null : reference.trim(),
            tip_amount: tip,
        });
    };

    return (
        <BottomSheet isOpen={open} onClose={onClose} title={title} className={className}>
            <div className="space-y-4">
                <div className="bg-muted/40 border-border rounded-xl border p-3">
                    <p className="text-muted-foreground text-xs">Total a cobrar</p>
                    <p className="text-foreground text-2xl font-semibold tabular-nums">
                        {formatCurrency(Number.parseFloat(amount))}
                    </p>
                    {tip > 0 && (
                        <p className="text-muted-foreground mt-1 text-xs tabular-nums">
                            + {formatCurrency(tip)} de propina ={' '}
                            <span className="text-foreground font-semibold">
                                {formatCurrency(Number.parseFloat(amount) + tip)}
                            </span>
                        </p>
                    )}
                </div>

                <div>
                    <Label className="text-xs">Método</Label>
                    <div
                        className="mt-1.5 grid gap-2"
                        style={{ gridTemplateColumns: `repeat(${catalog.methods.length}, minmax(0, 1fr))` }}
                    >
                        {catalog.methods.map((key) => {
                            const Icon = METHOD_ICONS[key];
                            return (
                                <Button
                                    key={key}
                                    type="button"
                                    variant={method === key ? 'default' : 'outline'}
                                    onClick={() => setMethod(key)}
                                    className="flex flex-col items-center gap-1 py-3"
                                >
                                    <Icon className="h-4 w-4" />
                                    <span className="text-xs">{catalog.labels[key]}</span>
                                </Button>
                            );
                        })}
                    </div>
                </div>

                {method === 'cash' && (
                    <div>
                        <Label htmlFor="cash-received" className="text-xs">
                            Efectivo recibido (opcional)
                        </Label>
                        <Input
                            id="cash-received"
                            type="text"
                            inputMode="numeric"
                            value={cashReceivedInput}
                            onChange={(e) => setCashReceivedInput(e.target.value.replace(/[^\d]/g, ''))}
                            placeholder="Con cuánto paga el cliente"
                            className="mt-1.5"
                        />
                        {cashReceived > 0 && (
                            <div
                                className={cn(
                                    'mt-1.5 flex items-center justify-between rounded-lg border px-3 py-2 text-sm',
                                    insufficientCash
                                        ? 'border-[color:var(--color-status-critical)]/40 text-[color:var(--color-status-critical)]'
                                        : 'border-[color:var(--color-status-safe)]/40 text-[color:var(--color-status-safe)]',
                                )}
                            >
                                <span>{insufficientCash ? 'Falta' : 'Cambio (devuelta)'}</span>
                                <span className="font-semibold tabular-nums">{formatCurrency(Math.abs(change))}</span>
                            </div>
                        )}
                    </div>
                )}

                {refRequired && (
                    <div>
                        <Label htmlFor="reference" className="text-xs">
                            Referencia (obligatorio)
                        </Label>
                        <Input
                            id="reference"
                            type="text"
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                            placeholder="Ej: voucher 12345"
                            maxLength={120}
                            className="mt-1.5"
                        />
                    </div>
                )}

                {showTip && (
                    <div>
                        <Label htmlFor="tip" className="text-xs">
                            Propina (opcional)
                        </Label>
                        <Input
                            id="tip"
                            type="text"
                            inputMode="numeric"
                            value={tipInput}
                            onChange={(e) => setTipInput(e.target.value.replace(/[^\d]/g, ''))}
                            placeholder="0"
                            className={cn('mt-1.5', tip > 0 && 'border-[color:var(--color-status-safe)]/50')}
                        />
                        <p className="text-muted-foreground mt-1 text-[11px]">
                            Voluntaria, no genera IVA ni suma al total contable.
                        </p>
                    </div>
                )}

                <Button
                    type="button"
                    size="lg"
                    className="w-full"
                    disabled={loading || !refValid}
                    onClick={() => void handleSubmit()}
                >
                    {loading ? 'Procesando…' : `Cobrar ${formatCurrency(Number.parseFloat(amount) + tip)}`}
                </Button>
            </div>
        </BottomSheet>
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
