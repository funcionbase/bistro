import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { sanitizePlainText } from '@/lib/input-sanitize';
import type { Ingredient, IngredientStock, TransferPayload, Warehouse } from '@/types/inventory';
import { AlertCircle, ArrowRight, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';

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
    // `error` = cross-field / servidor no atribuible a un campo (origen=destino).
    // `fieldErrors` = error de un input puntual → se muestra debajo de ese input.
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    // El modal vive siempre montado (patrón RecordEntryModal/IngredientFormModal):
    // al abrir se resetea el estado y se aplican los initial* vigentes.
    useEffect(() => {
        if (open) {
            setFromWarehouseId(initialFromWarehouseId ?? activeWarehouses[0]?.id ?? '');
            setToWarehouseId(activeWarehouses[1]?.id ?? '');
            setIngredientId(initialIngredientId ?? '');
            setQuantity('');
            setReference('');
            setError(null);
            setFieldErrors({});
        }
    }, [open, initialIngredientId, initialFromWarehouseId, activeWarehouses]);

    const selectedIngredient = useMemo(() => ingredients.find((i) => i.id === ingredientId), [ingredients, ingredientId]);

    const stockInOrigin = useMemo<IngredientStock | undefined>(() => {
        if (!selectedIngredient) return undefined;
        return selectedIngredient.stocks.find((s) => s.warehouse_id === fromWarehouseId);
    }, [selectedIngredient, fromWarehouseId]);

    const availableQty = stockInOrigin ? Number(stockInOrigin.quantity) : 0;

    const submit: FormEventHandler<HTMLFormElement> = async (e) => {
        e.preventDefault();
        setError(null);
        setFieldErrors({});

        // Validaciones cliente: las de campo van bajo su input; origen=destino
        // es cross-field → mensaje de form.
        const fe: Record<string, string> = {};
        if (!ingredientId) {
            fe.ingredient_id = 'Selecciona el insumo a transferir.';
        }
        const qty = Number(quantity);
        if (!qty || qty <= 0) {
            fe.quantity = 'La cantidad debe ser positiva.';
        } else if (qty > availableQty) {
            fe.quantity = `Solo hay ${availableQty} ${selectedIngredient?.unit ?? ''} disponibles en origen.`;
        }
        if (fromWarehouseId === toWarehouseId) {
            setError('La bodega de origen y destino no pueden ser la misma.');
        }
        if (Object.keys(fe).length > 0 || fromWarehouseId === toWarehouseId) {
            setFieldErrors(fe);
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
            // 422 con errores por campo → inline; si no, mensaje de form.
            if (apiErr.errors) {
                const mapped: Record<string, string> = {};
                for (const [field, messages] of Object.entries(apiErr.errors)) {
                    mapped[field] = messages[0] ?? '';
                }
                setFieldErrors(mapped);
            } else {
                setError(apiErr.message ?? 'No se pudo completar la transferencia.');
            }
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

                <form noValidate onSubmit={submit} className="space-y-4">
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
                        <InputError message={fieldErrors.ingredient_id} className="text-xs" />
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
                            step="1"
                            min="1"
                            required
                            value={quantity}
                            onChange={(e) => setQuantity(e.target.value)}
                            placeholder={selectedIngredient ? `0 ${selectedIngredient.unit}` : '0'}
                            aria-invalid={!!fieldErrors.quantity}
                        />
                        <InputError message={fieldErrors.quantity} className="text-xs" />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="transfer_ref">Referencia (opcional)</Label>
                        <Input
                            id="transfer_ref"
                            value={reference}
                            onChange={(e) => setReference(sanitizePlainText(e.target.value, 255, false, false))}
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
