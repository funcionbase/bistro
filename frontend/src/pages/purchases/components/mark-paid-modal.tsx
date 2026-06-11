import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { PurchasePaymentMethod } from '@/types/purchases';
import { PAYMENT_LABELS } from '@/types/purchases';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
    onConfirm: (method: PurchasePaymentMethod, reference?: string) => Promise<void>;
    submitting: boolean;
    errors: Record<string, string[]>;
}

export function MarkPaidModal({ open, onClose, onConfirm, submitting, errors }: Props) {
    const [method, setMethod] = useState<PurchasePaymentMethod>('cash');
    const [reference, setReference] = useState('');

    useEffect(() => {
        if (open) {
            setMethod('cash');
            setReference('');
        }
    }, [open]);

    const err = (f: string) => errors[f]?.[0];
    const requiresRef = method !== 'cash';

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        await onConfirm(method, reference.trim() || undefined);
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Registrar pago al proveedor</DialogTitle>
                    <DialogDescription>El pago marca la orden como `paid` — terminal salvo anulación.</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>Método</Label>
                        <div className="flex gap-2">
                            {(['cash', 'card', 'transfer'] as PurchasePaymentMethod[]).map((m) => (
                                <button
                                    key={m}
                                    type="button"
                                    onClick={() => setMethod(m)}
                                    className={cn(
                                        'flex-1 rounded-md border px-3 py-2 text-sm transition-colors',
                                        method === m
                                            ? 'bg-primary text-primary-foreground border-primary font-semibold'
                                            : 'bg-card hover:bg-muted/40 border-input',
                                    )}
                                >
                                    {PAYMENT_LABELS[m]}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="reference">Referencia {requiresRef ? '(obligatoria)' : '(opcional)'}</Label>
                        <Input
                            id="reference"
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                            required={requiresRef}
                            maxLength={120}
                            placeholder={requiresRef ? 'N° comprobante / autorización' : ''}
                        />
                        {err('payment_reference') && <p className="text-destructive text-xs">{err('payment_reference')}</p>}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                            Confirmar pago
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
