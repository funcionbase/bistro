import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { sanitizePlainText } from '@/lib/input-sanitize';
import type { Ingredient } from '@/types/inventory';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
    onSubmit: (body: { quantity: number; reference: string }) => Promise<void>;
    ingredient: Ingredient | null;
    submitting: boolean;
    errors: Record<string, string[]>;
}

export function RecordWasteModal({ open, onClose, onSubmit, ingredient, submitting, errors }: Props) {
    const [quantity, setQuantity] = useState('');
    const [reference, setReference] = useState('');

    useEffect(() => {
        if (open) {
            setQuantity('');
            setReference('');
        }
    }, [open]);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        await onSubmit({
            quantity: Number(quantity),
            reference: reference.trim(),
        });
    }

    function err(field: string): string | undefined {
        return errors[field]?.[0];
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Registrar merma</DialogTitle>
                    <DialogDescription>
                        {ingredient ? (
                            <>
                                Insumo: <span className="font-medium">{ingredient.name}</span> · existencias totales {ingredient.total_stock}{' '}
                                {ingredient.unit}
                            </>
                        ) : null}
                    </DialogDescription>
                </DialogHeader>

                <form noValidate onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="waste_qty">Cantidad a descartar ({ingredient?.unit})</Label>
                        <Input
                            id="waste_qty"
                            type="number"
                            step="any"
                            min="0"
                            value={quantity}
                            onChange={(e) => setQuantity(e.target.value)}
                            required
                        />
                        {err('quantity') && <p className="text-destructive text-xs">{err('quantity')}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="waste_ref">Motivo (obligatorio)</Label>
                        <Input
                            id="waste_ref"
                            value={reference}
                            onChange={(e) => setReference(sanitizePlainText(e.target.value, 255, false, false))}
                            placeholder="Vencimiento, contaminación, derrame…"
                            required
                            minLength={3}
                            maxLength={255}
                        />
                        {err('reference') && <p className="text-destructive text-xs">{err('reference')}</p>}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        {/* Guard cliente: cantidad > 0 y motivo min 3 (espeja el FormRequest; el form es noValidate). */}
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={submitting || !(Number(quantity) > 0) || reference.trim().length < 3}
                        >
                            {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                            Registrar merma
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
