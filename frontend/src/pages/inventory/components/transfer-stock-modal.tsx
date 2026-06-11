import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Ingredient, IngredientStock, TransferPayload, Warehouse } from '@/types/inventory';
import { AlertCircle, ArrowRight, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
    warehouses: Warehouse[];
    ingredients: Ingredient[];
    initialIngredientId?: string;
    initialFromWarehouseId?: string;
    onSubmit: (payload: TransferPayload) => Promise<void>;
}

/**
 * Modal para transferir stock entre bodegas de la misma sede.
 *
 * Validaciones cliente:
 *  - Origen ≠ destino.
 *  - Cantidad > 0.
 *  - Cantidad ≤ stock disponible en origen.
 *
 * El backend revalida + bloquea cross-branch (origen y destino deben compartir branch_id).
 */
export function TransferStockModal({ open, onClose, warehouses, ingredients, initialIngredientId, initialFromWarehouseId, onSubmit }: Props) {
    const activeWarehouses = useMemo(() => warehouses.filter((w) => !w.archived_at), [warehouses]);

    const [fromWarehouseId, setFromWarehouseId] = useState<string>(initialFromWarehouseId ?? activeWarehouses[0]?.id ?? '');
    const [toWarehouseId, setToWarehouseId] = useState<string>(activeWarehouses[1]?.id ?? '');
    const [ingredientId, setIngredientId] = useState<string>(initialIngredientId ?? '');
    const [quantity, setQuantity] = useState<string>('');
    const [reference, setReference] = useState<string>('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const selectedIngredient = useMemo(() => ingredients.find((i) => i.id === ingredientId), [ingredients, ingredientId]);

    const stockInOrigin = useMemo<IngredientStock | undefined>(() => {
        if (!selectedIngredient) return undefined;
        return selectedIngredient.stocks.find((s) => s.warehouse_id === fromWarehouseId);
    }, [selectedIngredient, fromWarehouseId]);

    const availableQty = stockInOrigin ? Number(stockInOrigin.quantity) : 0;

    const submit: FormEventHandler<HTMLFormElement> = async (e) => {
        e.preventDefault();
        setError(null);

        if (!ingredientId) {
            setError('Selecciona el insumo a transferir.');
            return;
        }
        if (fromWarehouseId === toWarehouseId) {
            setError('La bodega de origen y destino no pueden ser la misma.');
            return;
        }
        const qty = Number(quantity);
        if (!qty || qty <= 0) {
            setError('La cantidad debe ser positiva.');
            return;
        }
        if (qty > availableQty) {
            setError(`Solo hay ${availableQty} ${selectedIngredient?.unit ?? ''} disponibles en origen.`);
            return;
        }

        setSubmitting(true);
        try {
            await onSubmit({
                from_warehouse_id: fromWarehouseId,
                to_warehouse_id: toWarehouseId,
                ingredient_id: ingredientId,
                quantity: qty,
                reference: reference || undefined,
            });
            onClose();
        } catch (err) {
            const apiErr = err as { errors?: Record<string, string[]>; message?: string };
            const first = apiErr.errors ? Object.values(apiErr.errors)[0]?.[0] : apiErr.message;
            setError(first ?? 'No se pudo completar la transferencia.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Transferir existencias entre bodegas</DialogTitle>
                    <DialogDescription>
                        Mueve insumo entre bodegas de la misma sede. El movimiento queda registrado como traslado en el historial.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-[1fr_auto_1fr] items-end gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="transfer_from">Desde</Label>
                            <select
                                id="transfer_from"
                                required
                                value={fromWarehouseId}
                                onChange={(e) => setFromWarehouseId(e.target.value)}
                                className="border-input bg-background flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-sm"
                            >
                                {activeWarehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <ArrowRight className="text-muted-foreground mb-2 h-5 w-5" aria-hidden="true" />
                        <div className="space-y-1.5">
                            <Label htmlFor="transfer_to">Hacia</Label>
                            <select
                                id="transfer_to"
                                required
                                value={toWarehouseId}
                                onChange={(e) => setToWarehouseId(e.target.value)}
                                className="border-input bg-background flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-sm"
                            >
                                {activeWarehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="transfer_ingredient">Insumo</Label>
                        <select
                            id="transfer_ingredient"
                            required
                            value={ingredientId}
                            onChange={(e) => setIngredientId(e.target.value)}
                            className="border-input bg-background flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-sm"
                        >
                            <option value="">Selecciona insumo…</option>
                            {ingredients
                                .filter((i) => !i.archived_at)
                                .map((i) => (
                                    <option key={i.id} value={i.id}>
                                        {i.name} ({i.unit})
                                    </option>
                                ))}
                        </select>
                        {selectedIngredient && (
                            <p className="text-muted-foreground text-xs">
                                Disponible en {stockInOrigin?.name ?? 'origen'}:{' '}
                                <strong className="tabular-nums">
                                    {availableQty} {selectedIngredient.unit}
                                </strong>
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="transfer_qty">Cantidad</Label>
                        <Input
                            id="transfer_qty"
                            type="number"
                            step="0.001"
                            min="0.001"
                            required
                            value={quantity}
                            onChange={(e) => setQuantity(e.target.value)}
                            placeholder={selectedIngredient ? `0.000 ${selectedIngredient.unit}` : '0.000'}
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="transfer_ref">Referencia (opcional)</Label>
                        <Input
                            id="transfer_ref"
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                            placeholder="Motivo o lote"
                            maxLength={255}
                        />
                    </div>

                    {error && (
                        <Alert variant="destructive">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                            Transferir
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
