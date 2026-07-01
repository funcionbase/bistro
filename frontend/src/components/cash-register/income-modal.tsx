import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CASH_INCOME_CATEGORIES, type CashIncomeCategory } from '@/hooks/use-cash-register';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { usePaymentMethods } from '@/hooks/use-payment-methods';
import { sanitizePlainText } from '@/lib/input-sanitize';
import type { PaymentMethod } from '@/types';
import { AlertCircle, PlusCircle } from 'lucide-react';
import { useState } from 'react';

/**
 * Modal para registrar una entrada de efectivo (aporte de socio, préstamo,
 * ajuste positivo) contra la sesión activa. Espejo de ExpenseModal.
 * Append-only en backend: este formulario solo crea, no edita ni borra.
 */
interface Props {
    onClose: () => void;
    onSubmit: (input: { amount: number; category: CashIncomeCategory; description?: string; payment_method?: PaymentMethod }) => Promise<void>;
}

export default function IncomeModal({ onClose, onSubmit }: Props) {
    const formatCurrency = useCurrencyFormatter();
    const catalog = usePaymentMethods();
    const [amount, setAmount] = useState('');
    const [category, setCategory] = useState<CashIncomeCategory>('aporte_socio');
    const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('cash');
    const [description, setDescription] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Strip separadores de miles (. ó ,) antes de parsear para que "$99.000"
    // COP no se interprete como 99 por el punto decimal de JS.
    const parsed = parseInt(amount.replace(/[^0-9]/g, ''), 10);
    const isValid = !isNaN(parsed) && parsed > 0;

    const submit = async () => {
        if (!isValid) {
            setError('El monto debe ser mayor a 0.');
            return;
        }
        setSubmitting(true);
        setError(null);
        try {
            await onSubmit({
                amount: parsed,
                category,
                description: description.trim() || undefined,
                payment_method: paymentMethod,
            });
            onClose();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <PlusCircle className="h-4 w-4 text-[color:var(--color-status-safe)]" />
                        Registrar entrada
                    </DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="space-y-1">
                        <Label htmlFor="income_category">Categoría</Label>
                        <select
                            id="income_category"
                            value={category}
                            onChange={(e) => setCategory(e.target.value as CashIncomeCategory)}
                            className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-1 focus-visible:outline-none"
                        >
                            {Object.entries(CASH_INCOME_CATEGORIES).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="income_amount">Monto</Label>
                        <Input
                            id="income_amount"
                            type="text"
                            inputMode="numeric"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                            placeholder="0"
                            autoFocus
                        />
                        {isValid && (
                            <p className="text-muted-foreground text-xs">
                                Entrada: <span className="tabular-nums">{formatCurrency(parsed)}</span>
                            </p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="income_method">Método</Label>
                        <select
                            id="income_method"
                            value={paymentMethod}
                            onChange={(e) => setPaymentMethod(e.target.value as PaymentMethod)}
                            className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-1 focus-visible:outline-none"
                        >
                            {catalog.methods.map((m) => (
                                <option key={m} value={m}>
                                    {catalog.labels[m]}
                                </option>
                            ))}
                        </select>
                        <p className="text-muted-foreground text-[11px]">Solo el efectivo afecta el arqueo del cajón físico.</p>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="income_description">Descripción (opcional)</Label>
                        <Input
                            id="income_description"
                            value={description}
                            onChange={(e) => setDescription(sanitizePlainText(e.target.value, 500, true, false))}
                            placeholder="Ej. aporte del socio para cambio"
                            maxLength={500}
                        />
                    </div>

                    {error && (
                        <Alert variant="destructive" className="p-2 [&>svg]:top-2 [&>svg]:left-2 [&>svg~*]:pl-5">
                            <AlertCircle className="h-3.5 w-3.5" />
                            <AlertDescription className="text-xs">{error}</AlertDescription>
                        </Alert>
                    )}

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button variant="outline" className="flex-1" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button className="flex-1" onClick={() => void submit()} disabled={submitting || !isValid}>
                            {submitting ? 'Registrando…' : 'Registrar entrada'}
                        </Button>
                    </DialogFooter>

                    <p className="text-muted-foreground text-[11px] leading-snug">
                        Las entradas son permanentes e irreversibles: para corregir una errónea, registra un egreso equivalente con descripción explícita.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
