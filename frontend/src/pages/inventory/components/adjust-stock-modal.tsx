import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

export function AdjustStockModal({ open, onClose, onSubmit, ingredient, submitting, errors }: Props) {
    const [direction, setDirection] = useState<'+' | '-'>('+');
    const [quantity, setQuantity] = useState('');
    const [reference, setReference] = useState('');

    useEffect(() => {
        if (open) {
            setDirection('+');
            setQuantity('');
            setReference('');
        }
    }, [open]);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const signed = (direction === '-' ? -1 : 1) * Number(quantity);
        await onSubmit({
            quantity: signed,
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
                    <DialogTitle>Ajuste manual de existencias</DialogTitle>
                    <DialogDescription>
                        {ingredient ? (
                            <>
                                Insumo: <span className="font-medium">{ingredient.name}</span> · existencias totales{' '}
                                {Number(ingredient.total_stock).toLocaleString('es-CO', { maximumFractionDigits: 3 })} {ingredient.unit}
                            </>
                        ) : null}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-[auto_1fr] gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="adj_dir">Dirección</Label>
                            <select
                                id="adj_dir"
                                value={direction}
                                onChange={(e) => setDirection(e.target.value as '+' | '-')}
                                className="border-input bg-background flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-sm"
                            >
                                <option value="+">+ Suma</option>
                                <option value="-">− Resta</option>
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="adj_qty">Cantidad ({ingredient?.unit})</Label>
                            <Input
                                id="adj_qty"
                                type="number"
                                step="1"
                                min="0"
                                value={quantity}
                                onChange={(e) => setQuantity(e.target.value)}
                                required
                            />
                            {err('quantity') && <p className="text-destructive text-xs">{err('quantity')}</p>}
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="adj_ref">Motivo (obligatorio)</Label>
                        <Input
                            id="adj_ref"
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                            placeholder="Conteo físico, conciliación, error de captura…"
                            required
                            minLength={3}
                            maxLength={255}
                        />
                        {err('reference') && <p className="text-destructive text-xs">{err('reference')}</p>}
                    </div>

                    <p className="text-muted-foreground text-xs">
                        El ajuste no recalcula el costo unitario. Para corregir un costo, registra una nueva entrada.
                    </p>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting || quantity === '' || Number(quantity) <= 0 || reference.trim().length < 3}>
                            {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                            Aplicar ajuste
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
