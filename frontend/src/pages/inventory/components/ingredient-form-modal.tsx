import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Ingredient, IngredientFormPayload, IngredientUnit } from '@/types/inventory';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

const UNITS: { value: IngredientUnit; label: string }[] = [
    { value: 'kg', label: 'kg — Kilogramos' },
    { value: 'g', label: 'g — Gramos' },
    { value: 'l', label: 'L — Litros' },
    { value: 'ml', label: 'ml — Mililitros' },
    { value: 'un', label: 'un — Unidades' },
];

/**
 * Trunca un valor numérico (string) a su parte entera, conservando '' como ''.
 * Usado para existencias mínimas de insumos en unidades discretas (`un`), que
 * no admiten fracción.
 */
function toIntegerString(value: string): string {
    if (value === '') {
        return '';
    }
    const n = Number(value);
    return Number.isFinite(n) ? String(Math.trunc(n)) : '';
}

interface Props {
    open: boolean;
    onClose: () => void;
    onSubmit: (payload: IngredientFormPayload) => Promise<void>;
    editing: Ingredient | null;
    submitting: boolean;
    errors: Record<string, string[]>;
}

export function IngredientFormModal({ open, onClose, onSubmit, editing, submitting, errors }: Props) {
    const [name, setName] = useState('');
    const [category, setCategory] = useState('');
    const [unit, setUnit] = useState<IngredientUnit>('kg');
    const [minStock, setMinStock] = useState('');
    const [initialStock, setInitialStock] = useState('');
    const [initialCost, setInitialCost] = useState('');

    useEffect(() => {
        if (open) {
            const nextUnit = editing?.unit ?? 'kg';
            setName(editing?.name ?? '');
            setCategory(editing?.category ?? '');
            setUnit(nextUnit);
            // min_stock vive en ingredient_stocks por bodega (#120). El form usa
            // el primer min_stock > 0 entre las bodegas como valor representativo.
            const repMin = editing?.stocks.find((s) => Number(s.min_stock) > 0)?.min_stock ?? '';
            // Unidades discretas (`un`) no admiten fracción: el backend guarda
            // "5.000" pero mostramos "5".
            setMinStock(nextUnit === 'un' ? toIntegerString(repMin) : repMin);
            setInitialStock('');
            setInitialCost('');
        }
    }, [open, editing]);

    // `un` (Unidades) es discreta — sin decimales en existencias mínimas.
    const isDiscreteUnit = unit === 'un';

    function err(field: string): string | undefined {
        return errors[field]?.[0];
    }

    function handleUnitChange(next: IngredientUnit) {
        setUnit(next);
        // Al pasar a unidades discretas, truncamos cualquier decimal que ya
        // estuviera en el campo de existencias mínimas.
        if (next === 'un') {
            setMinStock((prev) => toIntegerString(prev));
        }
    }

    function handleMinStockChange(value: string) {
        // En unidades discretas solo permitimos dígitos (sin punto/coma ni signo).
        setMinStock(isDiscreteUnit ? value.replace(/\D/g, '') : value);
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const payload: IngredientFormPayload = {
            name: name.trim(),
            category: category.trim() || null,
            unit,
            min_stock: minStock !== '' ? Number(minStock) : 0,
        };
        if (!editing && initialStock && initialCost) {
            payload.initial_stock = Number(initialStock);
            payload.initial_cost = Number(initialCost);
            payload.reference = 'Existencias iniciales';
        }
        await onSubmit(payload);
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{editing ? 'Editar insumo' : 'Crear insumo'}</DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'Solo metadatos. Para cambiar existencias o costo registra un movimiento.'
                            : 'Si declaras existencias iniciales, se registrarán como una entrada con su costo unitario.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="name">Nombre</Label>
                        <Input id="name" value={name} onChange={(e) => setName(e.target.value)} required maxLength={150} />
                        {err('name') && <p className="text-destructive text-xs">{err('name')}</p>}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="category">Categoría (opcional)</Label>
                            <Input
                                id="category"
                                value={category}
                                onChange={(e) => setCategory(e.target.value)}
                                placeholder="Carnes, Verduras, Lácteos…"
                                maxLength={64}
                            />
                            {err('category') && <p className="text-destructive text-xs">{err('category')}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="unit">Unidad</Label>
                            <select
                                id="unit"
                                value={unit}
                                onChange={(e) => handleUnitChange(e.target.value as IngredientUnit)}
                                className="border-input bg-background flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-sm"
                            >
                                {UNITS.map((u) => (
                                    <option key={u.value} value={u.value}>
                                        {u.label}
                                    </option>
                                ))}
                            </select>
                            {err('unit') && <p className="text-destructive text-xs">{err('unit')}</p>}
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="min_stock">Existencias mínimas (alerta)</Label>
                        <Input
                            id="min_stock"
                            type="number"
                            step="1"
                            min="0"
                            inputMode="numeric"
                            value={minStock}
                            onChange={(e) => handleMinStockChange(e.target.value)}
                            placeholder="0 = sin alerta"
                        />
                        {err('min_stock') && <p className="text-destructive text-xs">{err('min_stock')}</p>}
                    </div>

                    {!editing && (
                        <div className="rounded-lg border border-dashed p-3">
                            <p className="text-muted-foreground mb-2 text-[11px] font-semibold tracking-[0.15em] uppercase">
                                Existencias iniciales (opcional)
                            </p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="initial_stock">Cantidad</Label>
                                    <Input
                                        id="initial_stock"
                                        type="number"
                                        step="1"
                                        min="0"
                                        value={initialStock}
                                        onChange={(e) => setInitialStock(e.target.value)}
                                    />
                                    {err('initial_stock') && <p className="text-destructive text-xs">{err('initial_stock')}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="initial_cost">Costo unitario (COP)</Label>
                                    <Input
                                        id="initial_cost"
                                        type="number"
                                        step="1"
                                        min="0"
                                        value={initialCost}
                                        onChange={(e) => setInitialCost(e.target.value)}
                                    />
                                    {err('initial_cost') && <p className="text-destructive text-xs">{err('initial_cost')}</p>}
                                </div>
                            </div>
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                            {editing ? 'Guardar' : 'Crear insumo'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
