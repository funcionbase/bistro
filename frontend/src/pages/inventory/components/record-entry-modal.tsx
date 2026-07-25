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
    onSubmit: (body: { quantity: number; unit_cost: number; reference?: string }) => Promise<void>;
    ingredient: Ingredient | null;
    submitting: boolean;
    errors: Record<string, string[]>;
}

export function RecordEntryModal({ open, onClose, onSubmit, ingredient, submitting, errors }: Props) {
    const [quantity, setQuantity] = useState('');
    const [unitCost, setUnitCost] = useState('');
    const [reference, setReference] = useState('');

    useEffect(() => {
        if (open) {
            setQuantity('');
            setUnitCost(ingredient?.current_cost && Number(ingredient.current_cost) > 0 ? ingredient.current_cost : '');
            setReference('');
        }
    }, [open, ingredient]);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        await onSubmit({
            quantity: Number(quantity),
            unit_cost: Number(unitCost),
            reference: reference.trim() || undefined,
        });
    }

    function err(field: string): string | undefined {
        return errors[field]?.[0];
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Registrar entrada</DialogTitle>
                    <DialogDescription>
                        {ingredient ? (
                            <>
                                Insumo: <span className="font-medium">{ingredient.name}</span> ({ingredient.unit})
                            </>
                        ) : null}
                    </DialogDescription>
                </DialogHeader>

                <form noValidate onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="entry_qty">Cantidad ({ingredient?.unit})</Label>
                            <Input
                                id="entry_qty"
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
                            <Label htmlFor="entry_cost">Costo por 1 {ingredient?.unit ?? 'unidad'} (COP)</Label>
                            <Input
                                id="entry_cost"
                                type="number"
                                step="1"
                                min="0"
                                value={unitCost}
                                onChange={(e) => setUnitCost(e.target.value)}
                                required
                            />
                            {err('unit_cost') && <p className="text-destructive text-xs">{err('unit_cost')}</p>}
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="entry_ref">Referencia (proveedor, factura)</Label>
                        <Input
                            id="entry_ref"
                            value={reference}
                            onChange={(e) => setReference(sanitizePlainText(e.target.value, 255, false, false))}
                            maxLength={255}
                        />
                        {err('reference') && <p className="text-destructive text-xs">{err('reference')}</p>}
                    </div>

                    <p className="text-muted-foreground text-xs">El costo se promedia ponderado con las existencias actuales.</p>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        {/* Guard cliente: cantidad > 0 y costo >= 0 (espeja el FormRequest; evita NaN). */}
                        <Button type="submit" disabled={submitting || !(Number(quantity) > 0) || unitCost === '' || !(Number(unitCost) >= 0)}>
                            {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                            Registrar entrada
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
