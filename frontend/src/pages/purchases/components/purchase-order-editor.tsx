import { Button } from '@/components/ui/button';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Ingredient, IngredientFormPayload } from '@/types/inventory';
import type { PurchaseOrderCreatePayload, PurchaseOrderDetail, PurchaseOrderItemPayload } from '@/types/purchases';
import type { Supplier } from '@/types/suppliers';
import { LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { IngredientFormModal } from '../../inventory/components/ingredient-form-modal';

interface LineDraft {
    ingredient_id: string;
    quantity: string;
    unit_cost: string;
    tax_rate: string;
}

const EMPTY_LINE: LineDraft = { ingredient_id: '', quantity: '', unit_cost: '', tax_rate: '0' };

interface Props {
    open: boolean;
    onClose: () => void;
    onSubmit: (payload: PurchaseOrderCreatePayload, isNew: boolean) => Promise<void>;
    editing: PurchaseOrderDetail | null;
    suppliers: Supplier[];
    ingredients: Ingredient[];
    submitting: boolean;
    errors: Record<string, string[]>;
    /** Si el usuario puede crear insumos (permiso `inventory.create`). */
    canCreateIngredient: boolean;
    /** Crea un insumo y lo devuelve para autoseleccionarlo en la línea. */
    onCreateIngredient: (payload: IngredientFormPayload) => Promise<Ingredient>;
    /** Aviso al padre para refrescar el catálogo de insumos. */
    onIngredientCreated?: () => void;
}

function fmt(n: number): string {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n);
}

/** Trunca un valor numérico (string) a su parte entera, conservando '' como ''. */
function toIntegerString(value: string): string {
    if (value === '') {
        return '';
    }
    const n = Number(value);
    return Number.isFinite(n) ? String(Math.trunc(n)) : '';
}

export function PurchaseOrderEditor({
    open,
    onClose,
    onSubmit,
    editing,
    suppliers,
    ingredients,
    submitting,
    errors,
    canCreateIngredient,
    onCreateIngredient,
    onIngredientCreated,
}: Props) {
    const [supplierId, setSupplierId] = useState<string>('');
    const [expectedDate, setExpectedDate] = useState('');
    const [notes, setNotes] = useState('');
    const [lines, setLines] = useState<LineDraft[]>([{ ...EMPTY_LINE }]);

    // Insumos creados dentro de este modal: se mezclan al catálogo al instante
    // (sin esperar el refetch del padre) para poder autoseleccionarlos.
    const [locallyCreated, setLocallyCreated] = useState<Ingredient[]>([]);
    const [showIngredientForm, setShowIngredientForm] = useState(false);
    const [ingredientLineIdx, setIngredientLineIdx] = useState<number | null>(null);
    const [creatingIngredient, setCreatingIngredient] = useState(false);
    const [ingredientErrors, setIngredientErrors] = useState<Record<string, string[]>>({});

    useEffect(() => {
        if (open) {
            setSupplierId(editing?.supplier?.id ?? '');
            setExpectedDate(editing?.expected_date ?? '');
            setNotes(editing?.notes ?? '');
            setLines(
                editing && editing.items.length
                    ? editing.items.map((i) => ({
                          ingredient_id: i.ingredient_id,
                          quantity: i.quantity,
                          unit_cost: i.unit_cost,
                          tax_rate: i.tax_rate,
                      }))
                    : [{ ...EMPTY_LINE }],
            );
            setLocallyCreated([]);
            setShowIngredientForm(false);
            setIngredientLineIdx(null);
            setIngredientErrors({});
        }
    }, [open, editing]);

    // Catálogo efectivo: props + recién creados (dedupe por id).
    const allIngredients = useMemo(() => {
        const map = new Map<string, Ingredient>();
        for (const i of ingredients) {
            map.set(i.id, i);
        }
        for (const i of locallyCreated) {
            map.set(i.id, i);
        }
        return Array.from(map.values());
    }, [ingredients, locallyCreated]);

    const supplierOptions = useMemo<ComboboxOption[]>(() => suppliers.map((s) => ({ value: s.id, label: s.name })), [suppliers]);
    const ingredientOptions = useMemo<ComboboxOption[]>(
        () => allIngredients.map((i) => ({ value: i.id, label: `${i.name} (${i.unit})` })),
        [allIngredients],
    );

    const totals = useMemo(() => {
        let subtotal = 0;
        let tax = 0;
        for (const l of lines) {
            const q = Number(l.quantity || 0);
            const c = Number(l.unit_cost || 0);
            const r = Number(l.tax_rate || 0);
            const sub = q * c;
            subtotal += sub;
            tax += (sub * r) / 100;
        }
        return { subtotal, tax, total: subtotal + tax };
    }, [lines]);

    function updateLine(idx: number, patch: Partial<LineDraft>) {
        setLines((prev) => prev.map((l, i) => (i === idx ? { ...l, ...patch } : l)));
    }

    // Al elegir insumo: si su unidad es discreta (`un`), trunca la cantidad.
    function setLineIngredient(idx: number, ingredientId: string) {
        const ing = allIngredients.find((i) => i.id === ingredientId);
        setLines((prev) =>
            prev.map((l, i) => {
                if (i !== idx) {
                    return l;
                }
                const next: LineDraft = { ...l, ingredient_id: ingredientId };
                if (ing?.unit === 'un') {
                    next.quantity = toIntegerString(next.quantity);
                }
                return next;
            }),
        );
    }

    function setLineQuantity(idx: number, raw: string, discrete: boolean) {
        updateLine(idx, { quantity: discrete ? raw.replace(/\D/g, '') : raw });
    }

    function addLine() {
        setLines((prev) => [...prev, { ...EMPTY_LINE }]);
    }
    function removeLine(idx: number) {
        setLines((prev) => (prev.length === 1 ? prev : prev.filter((_, i) => i !== idx)));
    }

    function openCreateIngredient(idx: number) {
        setIngredientLineIdx(idx);
        setIngredientErrors({});
        setShowIngredientForm(true);
    }

    async function handleCreateIngredient(payload: IngredientFormPayload) {
        setCreatingIngredient(true);
        setIngredientErrors({});
        try {
            const created = await onCreateIngredient(payload);
            setLocallyCreated((prev) => [created, ...prev]);
            if (ingredientLineIdx !== null) {
                const targetIdx = ingredientLineIdx;
                setLines((prev) =>
                    prev.map((l, i) =>
                        i === targetIdx
                            ? { ...l, ingredient_id: created.id, quantity: created.unit === 'un' ? toIntegerString(l.quantity) : l.quantity }
                            : l,
                    ),
                );
            }
            setShowIngredientForm(false);
            setIngredientLineIdx(null);
            onIngredientCreated?.();
        } catch (err) {
            const apiErr = err as { errors?: Record<string, string[]>; message?: string };
            setIngredientErrors(apiErr?.errors ?? { name: [apiErr?.message ?? 'No se pudo crear el insumo.'] });
        } finally {
            setCreatingIngredient(false);
        }
    }

    const err = (f: string) => errors[f]?.[0];

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!supplierId) return;

        const payloadItems: PurchaseOrderItemPayload[] = lines
            .filter((l) => l.ingredient_id && Number(l.quantity) > 0)
            .map((l) => ({
                ingredient_id: l.ingredient_id,
                quantity: Number(l.quantity),
                unit_cost: Number(l.unit_cost || 0),
                tax_rate: Number(l.tax_rate || 0),
            }));

        await onSubmit(
            {
                supplier_id: supplierId,
                expected_date: expectedDate || null,
                notes: notes.trim() || null,
                items: payloadItems,
            },
            !editing,
        );
    }

    return (
        <>
            <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Editar ${editing.code}` : 'Nueva orden de compra'}</DialogTitle>
                        <DialogDescription>Borrador. Las líneas usan precios netos; el impuesto se desglosa por línea.</DialogDescription>
                    </DialogHeader>

                    <form noValidate onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="supplier_id">Proveedor</Label>
                                <Combobox
                                    id="supplier_id"
                                    value={supplierId}
                                    onChange={setSupplierId}
                                    options={supplierOptions}
                                    placeholder="Selecciona…"
                                    searchPlaceholder="Buscar proveedor…"
                                    emptyText="No hay proveedores."
                                    disabled={!!editing}
                                />
                                {err('supplier_id') && <p className="text-destructive text-xs">{err('supplier_id')}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="expected_date">Fecha esperada (opcional)</Label>
                                <Input id="expected_date" type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
                            </div>
                        </div>

                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <Label>Líneas</Label>
                                <Button type="button" size="sm" variant="outline" onClick={addLine}>
                                    <Plus className="mr-1 h-3.5 w-3.5" /> Agregar línea
                                </Button>
                            </div>

                            {lines.map((l, idx) => {
                                const unit = allIngredients.find((i) => i.id === l.ingredient_id)?.unit;
                                const discrete = unit === 'un';
                                const q = Number(l.quantity || 0);
                                const c = Number(l.unit_cost || 0);
                                const r = Number(l.tax_rate || 0);
                                const lineTotal = q * c * (1 + r / 100);
                                return (
                                    <div key={idx} className="bg-muted/20 space-y-3 rounded-lg border p-3">
                                        <div className="space-y-1.5">
                                            <Label>Insumo</Label>
                                            <Combobox
                                                value={l.ingredient_id}
                                                onChange={(v) => setLineIngredient(idx, v)}
                                                options={ingredientOptions}
                                                placeholder="Selecciona un insumo…"
                                                searchPlaceholder="Buscar insumo…"
                                                emptyText="No hay insumos."
                                                footer={
                                                    canCreateIngredient ? (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="w-full justify-start"
                                                            onClick={() => openCreateIngredient(idx)}
                                                        >
                                                            <Plus className="mr-1 h-3.5 w-3.5" /> Crear insumo
                                                        </Button>
                                                    ) : undefined
                                                }
                                            />
                                        </div>
                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:items-end">
                                            <div className="space-y-1.5">
                                                <Label>Cantidad{unit ? ` (${unit})` : ''}</Label>
                                                <Input
                                                    type="number"
                                                    step="1"
                                                    min="0"
                                                    inputMode="numeric"
                                                    value={l.quantity}
                                                    onChange={(e) => setLineQuantity(idx, e.target.value, discrete)}
                                                    required
                                                    className="h-8"
                                                />
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label>Costo unit. neto</Label>
                                                <Input
                                                    type="number"
                                                    step="1"
                                                    min="0"
                                                    value={l.unit_cost}
                                                    onChange={(e) => updateLine(idx, { unit_cost: e.target.value })}
                                                    required
                                                    className="h-8"
                                                />
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label>IVA %</Label>
                                                <Input
                                                    type="number"
                                                    step="1"
                                                    min="0"
                                                    max="100"
                                                    value={l.tax_rate}
                                                    onChange={(e) => updateLine(idx, { tax_rate: e.target.value })}
                                                    className="h-8"
                                                />
                                            </div>
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="text-sm">
                                                    <div className="text-muted-foreground text-xs">Total</div>
                                                    <div className="font-semibold tabular-nums">{fmt(lineTotal)}</div>
                                                </div>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => removeLine(idx)}
                                                    disabled={lines.length === 1}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        <div className="bg-muted/40 grid gap-2 rounded-lg p-3 text-sm sm:grid-cols-3">
                            <div>
                                Subtotal: <span className="font-semibold tabular-nums">{fmt(totals.subtotal)}</span>
                            </div>
                            <div>
                                Impuesto: <span className="font-semibold tabular-nums">{fmt(totals.tax)}</span>
                            </div>
                            <div>
                                Total: <span className="font-semibold tabular-nums">{fmt(totals.total)}</span>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="notes">Notas</Label>
                            <textarea
                                id="notes"
                                rows={2}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm shadow-sm"
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={submitting}>
                                {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                                {editing ? 'Guardar borrador' : 'Crear borrador'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <IngredientFormModal
                open={showIngredientForm}
                onClose={() => {
                    setShowIngredientForm(false);
                    setIngredientLineIdx(null);
                }}
                onSubmit={handleCreateIngredient}
                editing={null}
                submitting={creatingIngredient}
                errors={ingredientErrors}
            />
        </>
    );
}
