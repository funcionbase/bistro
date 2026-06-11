import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ui/toast';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useRecipes } from '@/hooks/use-recipes';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import type { Ingredient, IngredientUnit, Warehouse } from '@/types/inventory';
import type { RecipeResponse } from '@/types/recipes';
import { AlertTriangle, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface RecipeEditorModalProps {
    open: boolean;
    onClose: () => void;
    menuId: string;
    itemId: string;
    itemName: string;
    itemPrice: number;
    onSaved?: (response: RecipeResponse) => void;
}

interface DraftLine {
    ingredient_id: string | null;
    quantity: string;
    unit: IngredientUnit | '';
    /** Bodega de costeo. '' = usar la default de la sede (backend resuelve). */
    warehouse_id: string;
    /** true si el backend marcó la línea sin costo en la bodega elegida. */
    misconfigured?: boolean;
}

const DIMENSION_BY_UNIT: Record<IngredientUnit, 'mass' | 'volume' | 'count'> = {
    kg: 'mass',
    g: 'mass',
    l: 'volume',
    ml: 'volume',
    un: 'count',
};

function compatibleUnits(ingredientUnit: IngredientUnit | undefined): IngredientUnit[] {
    if (!ingredientUnit) return ['kg', 'g', 'l', 'ml', 'un'];
    const dim = DIMENSION_BY_UNIT[ingredientUnit];
    return (Object.entries(DIMENSION_BY_UNIT) as Array<[IngredientUnit, 'mass' | 'volume' | 'count']>).filter(([, d]) => d === dim).map(([u]) => u);
}

export default function RecipeEditorModal({ open, onClose, menuId, itemId, itemName, itemPrice, onSaved }: RecipeEditorModalProps) {
    const { fetchRecipe, upsertRecipe } = useRecipes();
    const { showToast } = useToast();
    const formatPrice = useCurrencyFormatter();
    const { activeBranch } = useSharedData();

    const [ingredients, setIngredients] = useState<Ingredient[]>([]);
    const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [draft, setDraft] = useState<DraftLine[]>([]);
    const [serverData, setServerData] = useState<RecipeResponse | null>(null);

    const ingredientById = useMemo(() => new Map(ingredients.map((i) => [i.id, i])), [ingredients]);
    const activeWarehouses = useMemo(() => warehouses.filter((w) => !w.archived_at), [warehouses]);
    const defaultWarehouseId = useMemo(
        () => activeWarehouses.find((w) => w.branches.some((b) => b.branch_id === activeBranch?.id && b.is_default))?.id ?? '',
        [activeWarehouses, activeBranch?.id],
    );

    /** WAC de un insumo en una bodega concreta (stock row); fallback al promedio del insumo. */
    function costForLine(ing: Ingredient | undefined, warehouseId: string): number {
        if (!ing) return 0;
        if (warehouseId) {
            const stock = ing.stocks.find((s) => s.warehouse_id === warehouseId);
            if (stock) return parseFloat(stock.current_cost);
        }
        return parseFloat(ing.current_cost);
    }

    useEffect(() => {
        if (!open) return;
        let alive = true;
        setLoading(true);
        const warehousesUrl = activeBranch?.id
            ? `/api/v1/company/warehouses?branch_id=${activeBranch.id}`
            : '/api/v1/company/warehouses';
        Promise.all([
            apiFetch('/api/v1/inventory/ingredients?per_page=200').then((r) => r.json()),
            fetchRecipe(menuId, itemId).catch(() => null),
            apiFetch(warehousesUrl)
                .then((r) => (r.ok ? r.json() : { data: [] }))
                .catch(() => ({ data: [] })),
        ])
            .then(([ingRes, recipe, whRes]) => {
                if (!alive) return;
                setIngredients(ingRes?.data ?? []);
                setWarehouses((whRes?.data ?? []) as Warehouse[]);
                if (recipe) {
                    setServerData(recipe);
                    setDraft(
                        recipe.items.map((line) => ({
                            ingredient_id: line.ingredient_id,
                            quantity: line.recipe_quantity,
                            unit: line.recipe_unit,
                            warehouse_id: line.warehouse_id ?? '',
                            misconfigured: line.misconfigured,
                        })),
                    );
                } else {
                    setDraft([]);
                }
            })
            .catch(() => {
                if (alive) showToast('error', 'No se pudieron cargar los datos de receta.');
            })
            .finally(() => {
                if (alive) setLoading(false);
            });
        return () => {
            alive = false;
        };
    }, [open, menuId, itemId, fetchRecipe, showToast, activeBranch?.id]);

    function addLine() {
        setDraft((prev) => [...prev, { ingredient_id: null, quantity: '', unit: '', warehouse_id: defaultWarehouseId }]);
    }

    function removeLine(idx: number) {
        setDraft((prev) => prev.filter((_, i) => i !== idx));
    }

    function updateLine(idx: number, patch: Partial<DraftLine>) {
        setDraft((prev) =>
            prev.map((line, i) => {
                if (i !== idx) return line;
                const next = { ...line, ...patch };
                if (patch.ingredient_id !== undefined) {
                    const ing = ingredientById.get(patch.ingredient_id ?? '');
                    if (ing) next.unit = ing.unit;
                }
                // Cambiar insumo o bodega invalida el flag persistido; se recalcula al guardar.
                if (patch.ingredient_id !== undefined || patch.warehouse_id !== undefined) {
                    next.misconfigured = undefined;
                }
                return next;
            }),
        );
    }

    // Vista previa local del costo total + margen.
    const previewTotal = useMemo(() => {
        let total = 0;
        for (const line of draft) {
            const ing = ingredientById.get(line.ingredient_id ?? '');
            if (!ing || !line.quantity || !line.unit) continue;
            const qty = parseFloat(line.quantity);
            const unitCost = costForLine(ing, line.warehouse_id);
            if (!Number.isFinite(qty) || !Number.isFinite(unitCost)) continue;
            // Conversión local idéntica a UnitConverter (g↔kg, ml↔l, un=un).
            let normalized = qty;
            if (line.unit !== ing.unit) {
                const factor =
                    (line.unit === 'kg' && ing.unit === 'g') || (line.unit === 'l' && ing.unit === 'ml')
                        ? 1000
                        : (line.unit === 'g' && ing.unit === 'kg') || (line.unit === 'ml' && ing.unit === 'l')
                          ? 0.001
                          : NaN;
                if (!Number.isFinite(factor)) continue;
                normalized = qty * factor;
            }
            total += normalized * unitCost;
        }
        return total;
    }, [draft, ingredientById]);

    const previewMarginPct = itemPrice > 0 ? (itemPrice - previewTotal) / itemPrice : null;
    const lowMargin = previewMarginPct !== null && previewMarginPct < 0.2;

    async function save() {
        // Validación local: filas completas y sin duplicados.
        const cleaned: DraftLine[] = draft.filter((d) => d.ingredient_id && d.quantity && d.unit);
        if (cleaned.length !== draft.length) {
            showToast('error', 'Cada línea requiere insumo, cantidad y unidad.');
            return;
        }
        const ids = cleaned.map((d) => d.ingredient_id);
        if (new Set(ids).size !== ids.length) {
            showToast('error', 'No se puede repetir el mismo insumo.');
            return;
        }

        setSaving(true);
        try {
            const response = await upsertRecipe(menuId, itemId, {
                items: cleaned.map((d) => ({
                    ingredient_id: d.ingredient_id as string,
                    quantity: parseFloat(d.quantity),
                    unit: d.unit as IngredientUnit,
                    warehouse_id: d.warehouse_id || undefined,
                })),
            });
            setServerData(response);
            showToast('success', 'Receta guardada.');
            onSaved?.(response);
            onClose();
        } catch (err) {
            const apiErr = err as { errors?: Record<string, string[]>; message?: string };
            const firstError = apiErr?.errors ? Object.values(apiErr.errors)[0]?.[0] : null;
            showToast('error', firstError ?? apiErr?.message ?? 'No se pudo guardar la receta.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        Receta · <span className="font-normal">{itemName}</span>
                    </DialogTitle>
                    <p className="text-muted-foreground text-sm">
                        Define el consumo de insumos por unidad vendida. El costo se descuenta del inventario al pasar la orden a cocina.
                    </p>
                </DialogHeader>

                {loading ? (
                    <div className="space-y-2">
                        <Skeleton className="h-10 w-full" />
                        <Skeleton className="h-10 w-full" />
                        <Skeleton className="h-10 w-full" />
                    </div>
                ) : (
                    <div className="space-y-3">
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Insumo</th>
                                        <th className="px-3 py-2 font-medium">Cantidad</th>
                                        <th className="px-3 py-2 font-medium">Unidad</th>
                                        <th className="px-3 py-2 font-medium">Bodega</th>
                                        <th className="px-3 py-2 text-right font-medium">Costo unit.</th>
                                        <th className="px-3 py-2 text-right font-medium">Total línea</th>
                                        <th className="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {draft.length === 0 ? (
                                        <tr>
                                            <td className="text-muted-foreground px-3 py-8 text-center" colSpan={7}>
                                                Sin líneas — agrega al menos un insumo o guarda vacío para limpiar la receta.
                                            </td>
                                        </tr>
                                    ) : (
                                        draft.map((line, idx) => {
                                            const ing = ingredientById.get(line.ingredient_id ?? '');
                                            const qty = parseFloat(line.quantity);
                                            const unitCost = costForLine(ing, line.warehouse_id);
                                            const factor =
                                                ing && line.unit && line.unit !== ing.unit
                                                    ? (line.unit === 'kg' && ing.unit === 'g') || (line.unit === 'l' && ing.unit === 'ml')
                                                        ? 1000
                                                        : 0.001
                                                    : 1;
                                            const lineTotal = ing && Number.isFinite(qty) && Number.isFinite(factor) ? qty * factor * unitCost : 0;
                                            // Sin costo en la bodega elegida: o lo marcó el backend, o el WAC local es 0 con bodega y cantidad definidas.
                                            const noCostInWarehouse =
                                                !!ing &&
                                                (line.misconfigured === true ||
                                                    (line.misconfigured === undefined && !!line.warehouse_id && unitCost <= 0));
                                            return (
                                                <tr key={idx} className="border-t align-top">
                                                    <td className="px-3 py-2">
                                                        <select
                                                            className="border-input bg-background w-full rounded border px-2 py-1"
                                                            value={line.ingredient_id ?? ''}
                                                            onChange={(e) => updateLine(idx, { ingredient_id: e.target.value || null })}
                                                        >
                                                            <option value="">Selecciona…</option>
                                                            {ingredients
                                                                .filter((i) => !i.archived_at)
                                                                .map((i) => (
                                                                    <option key={i.id} value={i.id}>
                                                                        {i.name} ({i.unit})
                                                                    </option>
                                                                ))}
                                                        </select>
                                                        {noCostInWarehouse && (
                                                            <Badge variant="warning" className="mt-1 gap-1 text-[10px]">
                                                                <AlertTriangle className="h-3 w-3" aria-hidden="true" />
                                                                Sin costo en esta bodega
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            type="number"
                                                            min="0"
                                                            step="0.001"
                                                            className="w-28"
                                                            value={line.quantity}
                                                            onChange={(e) => updateLine(idx, { quantity: e.target.value })}
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <select
                                                            className="border-input bg-background rounded border px-2 py-1"
                                                            value={line.unit}
                                                            onChange={(e) => updateLine(idx, { unit: e.target.value as IngredientUnit })}
                                                        >
                                                            <option value="">—</option>
                                                            {compatibleUnits(ing?.unit).map((u) => (
                                                                <option key={u} value={u}>
                                                                    {u}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <select
                                                            className="border-input bg-background w-full min-w-32 rounded border px-2 py-1"
                                                            value={line.warehouse_id}
                                                            onChange={(e) => updateLine(idx, { warehouse_id: e.target.value })}
                                                        >
                                                            <option value="">Default de la sede</option>
                                                            {activeWarehouses.map((w) => (
                                                                <option key={w.id} value={w.id}>
                                                                    {w.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </td>
                                                    <td className="px-3 py-2 text-right">{ing ? formatPrice(unitCost) : '—'}</td>
                                                    <td className="px-3 py-2 text-right font-medium">
                                                        {ing && Number.isFinite(lineTotal) ? formatPrice(lineTotal) : '—'}
                                                    </td>
                                                    <td className="px-3 py-2 text-right">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="text-destructive hover:text-destructive h-7 w-7"
                                                            onClick={() => removeLine(idx)}
                                                        >
                                                            <Trash2 className="h-3.5 w-3.5" />
                                                        </Button>
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex items-center justify-between">
                            <Button variant="outline" size="sm" onClick={addLine}>
                                <Plus className="mr-1 h-3.5 w-3.5" /> Agregar insumo
                            </Button>
                            <div className="flex flex-col items-end gap-1 text-right">
                                <div className="text-sm">
                                    <span className="text-muted-foreground">Costo total receta: </span>
                                    <span className="font-semibold">{formatPrice(previewTotal)}</span>
                                </div>
                                {previewMarginPct !== null && (
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className="text-muted-foreground">Precio: {formatPrice(itemPrice)} ·</span>
                                        <span
                                            className={
                                                lowMargin
                                                    ? 'font-semibold text-[color:var(--color-status-warning)]'
                                                    : 'font-semibold text-[color:var(--color-status-safe)]'
                                            }
                                        >
                                            Margen: {(previewMarginPct * 100).toFixed(1)}%
                                        </span>
                                        {lowMargin && (
                                            <Badge variant="warning" className="text-xs">
                                                ⚠ bajo
                                            </Badge>
                                        )}
                                    </div>
                                )}
                                {serverData && (
                                    <p className="text-muted-foreground text-xs">
                                        Costo persistido: {formatPrice(parseFloat(serverData.total_cost))}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                <div className="flex justify-end gap-2 pt-2">
                    <Button variant="outline" onClick={onClose} disabled={saving}>
                        Cancelar
                    </Button>
                    <Button onClick={save} disabled={saving || loading}>
                        {saving ? 'Guardando…' : 'Guardar receta'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
