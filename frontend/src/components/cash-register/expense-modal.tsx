import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CASH_EXPENSE_CATEGORIES, type CashExpenseCategory } from '@/hooks/use-cash-register';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { usePaymentMethods } from '@/hooks/use-payment-methods';
import type { PaymentMethod } from '@/types';
import { apiFetch } from '@/lib/api';
import { AlertCircle, MinusCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Modal para registrar un egreso de caja contra la sesión activa.
 * Append-only en backend: este formulario solo crea, no edita ni borra.
 */
interface Props {
    onClose: () => void;
    onSubmit: (input: {
        amount: number;
        category: CashExpenseCategory;
        description?: string;
        payment_method?: PaymentMethod;
        courier_user_id?: string | null;
    }) => Promise<void>;
}

export default function ExpenseModal({ onClose, onSubmit }: Props) {
    const formatCurrency = useCurrencyFormatter();
    const catalog = usePaymentMethods();
    const [amount, setAmount] = useState('');
    const [category, setCategory] = useState<CashExpenseCategory>('domiciliario_pago');
    const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('cash');
    const [description, setDescription] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // F6: pago a domiciliario vinculado al repartidor (cruce del cierre).
    const [couriers, setCouriers] = useState<{ id: string; name: string }[]>([]);
    const [courierId, setCourierId] = useState<string>('');

    useEffect(() => {
        if (category !== 'domiciliario_pago' || couriers.length > 0) return;
        void (async () => {
            try {
                const res = await apiFetch('/api/v1/deliveries/couriers');
                if (!res.ok) return;
                const json = (await res.json().catch(() => null)) as { data?: { id: string; name: string }[] } | null;
                if (json?.data) setCouriers(json.data.map((c) => ({ id: c.id, name: c.name })));
            } catch {
                // Selector opcional: sin couriers el egreso sigue funcionando.
            }
        })();
    }, [category, couriers.length]);

    // BUG-003: strip separadores de miles (. ó ,) antes de parsear para que
    // "$99.000" COP no se interprete como 99 por el punto decimal de JS.
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
                courier_user_id: category === 'domiciliario_pago' && courierId ? courierId : null,
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
                        <MinusCircle className="h-4 w-4 text-[color:var(--color-status-warning)]" />
                        Registrar egreso
                    </DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="space-y-1">
                        <Label htmlFor="expense_category">Categoría</Label>
                        <select
                            id="expense_category"
                            value={category}
                            onChange={(e) => setCategory(e.target.value as CashExpenseCategory)}
                            className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-1 focus-visible:outline-none"
                        >
                            {Object.entries(CASH_EXPENSE_CATEGORIES).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </div>

                    {category === 'domiciliario_pago' && couriers.length > 0 && (
                        <div className="space-y-1">
                            <Label htmlFor="expense_courier">Domiciliario (para el cruce del cierre)</Label>
                            <select
                                id="expense_courier"
                                value={courierId}
                                onChange={(e) => setCourierId(e.target.value)}
                                className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-1 focus-visible:outline-none"
                            >
                                <option value="">Sin vincular</option>
                                {couriers.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    <div className="space-y-1">
                        <Label htmlFor="expense_amount">Monto</Label>
                        <Input
                            id="expense_amount"
                            type="text"
                            inputMode="numeric"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                            placeholder="0"
                            autoFocus
                        />
                        {isValid && (
                            <p className="text-muted-foreground text-xs">
                                Egreso: <span className="tabular-nums">{formatCurrency(parsed)}</span>
                            </p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="expense_method">Método</Label>
                        <select
                            id="expense_method"
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
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="expense_description">Descripción (opcional)</Label>
                        <Input
                            id="expense_description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            placeholder="Ej. domicilio Rappi orden #1234"
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
                            {submitting ? 'Registrando…' : 'Registrar egreso'}
                        </Button>
                    </DialogFooter>

                    <p className="text-muted-foreground text-[11px] leading-snug">
                        Los egresos son permanentes e irreversibles: para corregir uno erróneo, registra otro en sentido contrario con descripción explícita.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
