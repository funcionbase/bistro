import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DataCard, DataCardList } from '@/components/ui/data-card-list';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { InventorySkeleton } from '@/components/ui/inventory-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { RefreshButton } from '@/components/ui/refresh-button';
import { StatTile } from '@/components/ui/stat-tile';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToast } from '@/components/ui/toast';
import { useInventory } from '@/hooks/use-inventory';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import { cn } from '@/lib/utils';
import type { Ingredient, IngredientFormPayload, TransferPayload, Warehouse } from '@/types/inventory';

import {
    AlertCircle,
    Archive,
    ArrowDownCircle,
    ArrowLeftRight,
    ArrowUpCircle,
    History,
    MoreVertical,
    Package,
    Pencil,
    Plus,
    Sliders,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { AdjustStockModal } from './components/adjust-stock-modal';
import { IngredientFormModal } from './components/ingredient-form-modal';
import { InventoryValuationChart } from './components/inventory-valuation-chart';
import { MovementsDrawer } from './components/movements-drawer';
import { RecordEntryModal } from './components/record-entry-modal';
import { RecordWasteModal } from './components/record-waste-modal';
import { TransferStockModal } from './components/transfer-stock-modal';
import { formatCurrency as formatCurrencyCOP } from '@/lib/formatters';


function formatStock(value: string, unit: string): string {
    const n = Number(value);
    return `${n.toLocaleString('es-CO', { maximumFractionDigits: 3 })} ${unit}`;
}

function formatCurrency(value: string | number): string {
    return formatCurrencyCOP(Number(value));
}

export default function InventoryIndex() {
    const token = useToken();
    const { showToast } = useToast();
    const inv = useInventory(token);

    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState<Ingredient | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const [entryFor, setEntryFor] = useState<Ingredient | null>(null);
    const [wasteFor, setWasteFor] = useState<Ingredient | null>(null);
    const [adjustFor, setAdjustFor] = useState<Ingredient | null>(null);
    const [historyFor, setHistoryFor] = useState<Ingredient | null>(null);

    const [confirmArchive, setConfirmArchive] = useState<Ingredient | null>(null);
    const [archiving, setArchiving] = useState(false);

    const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
    const [transferOpen, setTransferOpen] = useState(false);
    const [transferIngredientId, setTransferIngredientId] = useState<string | undefined>(undefined);

    const { activeBranch } = useSharedData();

    useEffect(() => {
        if (!token || !activeBranch?.id) return;
        void (async () => {
            try {
                const res = await apiFetch(`/api/v1/company/warehouses?branch_id=${activeBranch.id}`);
                if (!res.ok) return;
                const json = (await res.json()) as { data: Warehouse[] };
                setWarehouses(json.data);
            } catch {
                /* silencio: si /company/warehouses falla, el inventario funciona
                   con la bodega default del backend */
            }
        })();
    }, [token, activeBranch?.id]);

    useEffect(() => {
        if (typeof window === 'undefined') return;
        const params = new URLSearchParams(window.location.search);
        if (params.get('low_stock') === '1') {
            inv.setFilters({ low_stock: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const totalValuation = useMemo(
        () =>
            inv.ingredients.reduce((sum, i) => {
                const stock = inv.filters.warehouse_id ? Number(i.filtered_stock?.quantity ?? 0) : Number(i.total_stock);
                return sum + stock * Number(i.current_cost);
            }, 0),
        [inv.ingredients, inv.filters.warehouse_id],
    );

    function handleApiError(err: unknown, fallback: string) {
        const apiErr = err as { errors?: Record<string, string[]>; message?: string };
        if (apiErr?.errors) {
            setErrors(apiErr.errors);
        } else {
            showToast('error', apiErr?.message ?? fallback);
        }
    }

    async function submitIngredient(payload: IngredientFormPayload) {
        setSubmitting(true);
        setErrors({});
        try {
            if (editing) {
                await inv.updateIngredient(editing.id, payload);
                showToast('success', `"${payload.name}" actualizado.`);
            } else {
                await inv.createIngredient(payload);
                showToast('success', `"${payload.name}" creado.`);
            }
            setShowForm(false);
            setEditing(null);
            await inv.fetchIngredients();
        } catch (err) {
            handleApiError(err, 'No se pudo guardar el insumo.');
        } finally {
            setSubmitting(false);
        }
    }

    async function submitEntry(body: { quantity: number; unit_cost: number; reference?: string }) {
        if (!entryFor) return;
        setSubmitting(true);
        setErrors({});
        try {
            await inv.recordEntry(entryFor.id, body);
            showToast('success', `Entrada registrada para ${entryFor.name}.`);
            setEntryFor(null);
            await inv.fetchIngredients();
        } catch (err) {
            handleApiError(err, 'No se pudo registrar la entrada.');
        } finally {
            setSubmitting(false);
        }
    }

    async function submitWaste(body: { quantity: number; reference: string }) {
        if (!wasteFor) return;
        setSubmitting(true);
        setErrors({});
        try {
            await inv.recordWaste(wasteFor.id, body);
            showToast('success', `Merma registrada para ${wasteFor.name}.`);
            setWasteFor(null);
            await inv.fetchIngredients();
        } catch (err) {
            handleApiError(err, 'No se pudo registrar la merma.');
        } finally {
            setSubmitting(false);
        }
    }

    async function submitAdjustment(body: { quantity: number; reference: string }) {
        if (!adjustFor) return;
        setSubmitting(true);
        setErrors({});
        try {
            await inv.recordAdjustment(adjustFor.id, body);
            showToast('success', `Ajuste aplicado a ${adjustFor.name}.`);
            setAdjustFor(null);
            await inv.fetchIngredients();
        } catch (err) {
            handleApiError(err, 'No se pudo aplicar el ajuste.');
        } finally {
            setSubmitting(false);
        }
    }

    async function doArchive() {
        if (!confirmArchive) return;
        setArchiving(true);
        const target = confirmArchive;
        setConfirmArchive(null);
        try {
            await inv.archiveIngredient(target.id);
            showToast('success', `"${target.name}" archivado.`);
            await inv.fetchIngredients();
        } catch (err) {
            handleApiError(err, 'No se pudo archivar el insumo.');
        } finally {
            setArchiving(false);
        }
    }

    async function doRestore(i: Ingredient) {
        try {
            await inv.restoreIngredient(i.id);
            showToast('success', `"${i.name}" restaurado.`);
            await inv.fetchIngredients();
        } catch (err) {
            handleApiError(err, 'No se pudo restaurar el insumo.');
        }
    }

    const activeWarehouses = warehouses.filter((w) => !w.archived_at);
    const hasWarehouses = warehouses.length > 0;
    const hasMultipleWarehouses = warehouses.length >= 2;
    const initialLoading = inv.loading && inv.ingredients.length === 0 && !inv.error;

    if (initialLoading) {
        return (
            <PageShell title="Inventario">
                <div className="p-4 sm:p-6">
                    <InventorySkeleton showWarehouseTabs={hasWarehouses} />
                </div>
            </PageShell>
        );
    }

    return (
        <PageShell title="Inventario">
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="OPERACIÓN"
                    title="Inventario"
                    description="Insumos, existencias y costo promedio. Movimientos append-only: los ajustes se registran como nuevos asientos."
                    actions={
                        <>
                            <RefreshButton onRefresh={() => inv.fetchIngredients()} />
                            {hasMultipleWarehouses && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setTransferIngredientId(undefined);
                                        setTransferOpen(true);
                                    }}
                                >
                                    <ArrowLeftRight className="mr-1 h-4 w-4" /> Transferir
                                </Button>
                            )}
                            <Button
                                size="sm"
                                onClick={() => {
                                    setEditing(null);
                                    setErrors({});
                                    setShowForm(true);
                                }}
                            >
                                <Plus className="mr-1 h-4 w-4" /> Crear insumo
                            </Button>
                        </>
                    }
                />

                {hasWarehouses && (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="text-muted-foreground">Bodega:</span>
                        <button
                            type="button"
                            className={cn(
                                'rounded-md border px-3 py-1.5 transition-colors',
                                inv.filters.warehouse_id === ''
                                    ? 'bg-primary text-primary-foreground border-primary font-medium'
                                    : 'bg-card hover:bg-muted/40 border-input',
                            )}
                            onClick={() => inv.setFilters({ warehouse_id: '' })}
                        >
                            Todas
                        </button>
                        {activeWarehouses.map((w) => (
                            <button
                                key={w.id}
                                type="button"
                                className={cn(
                                    'rounded-md border px-3 py-1.5 transition-colors',
                                    inv.filters.warehouse_id === w.id
                                        ? 'bg-primary text-primary-foreground border-primary font-medium'
                                        : 'bg-card hover:bg-muted/40 border-input',
                                )}
                                onClick={() => inv.setFilters({ warehouse_id: w.id })}
                            >
                                {w.name}
                            </button>
                        ))}
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatTile size="lg" label="Total insumos" value={inv.ingredients.length} />
                    <StatTile
                        size="lg"
                        tone={inv.meta.low_stock_count > 0 ? 'warning' : 'default'}
                        label="Bajo mínimo"
                        value={inv.meta.low_stock_count}
                    />
                    <StatTile size="lg" label="Valorización (visibles)" value={formatCurrency(totalValuation)} />
                </div>

                <FilterBar
                    variant="card"
                    searchValue={inv.filters.q}
                    onSearchChange={(value) => inv.setFilters({ q: value })}
                    searchPlaceholder="Buscar por nombre…"
                >
                    <select
                        value={inv.filters.category}
                        onChange={(e) => inv.setFilters({ category: e.target.value })}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm shadow-sm"
                    >
                        <option value="">Todas las categorías</option>
                        {inv.meta.categories.map((c) => (
                            <option key={c} value={c}>
                                {c}
                            </option>
                        ))}
                    </select>
                    <label className="text-muted-foreground flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={inv.filters.low_stock} onChange={(e) => inv.setFilters({ low_stock: e.target.checked })} />
                        Solo bajo mínimo
                    </label>
                    <label className="text-muted-foreground flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={inv.filters.archived} onChange={(e) => inv.setFilters({ archived: e.target.checked })} />
                        Ver archivados
                    </label>
                </FilterBar>

                {inv.error ? (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{inv.error}</AlertDescription>
                    </Alert>
                ) : null}

                {inv.ingredients.length === 0 && !inv.error ? (
                    <div className="bg-card rounded-lg border shadow-sm">
                        <EmptyState
                            icon={Package}
                            title="Sin insumos con los filtros actuales"
                            description="Cambia los filtros o crea tu primer insumo para comenzar a controlar las existencias y los costos."
                            action={
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setEditing(null);
                                        setErrors({});
                                        setShowForm(true);
                                    }}
                                >
                                    <Plus className="mr-1 h-4 w-4" /> Crear insumo
                                </Button>
                            }
                        />
                    </div>
                ) : inv.ingredients.length > 0 ? (
                    <>
                        {/* Mobile: card-stack con kebab para acciones */}
                        <DataCardList
                            items={inv.ingredients}
                            getKey={(i) => i.id}
                            className="sm:hidden"
                            renderCard={(i) => {
                                const isArchived = i.archived_at !== null;
                                const stockValue = inv.filters.warehouse_id ? (i.filtered_stock?.quantity ?? '0.000') : i.total_stock;
                                return (
                                    <DataCard
                                        title={i.name}
                                        subtitle={i.category ?? undefined}
                                        fields={[
                                            {
                                                label: 'Existencias',
                                                value: <span className="tabular-nums">{formatStock(stockValue, i.unit)}</span>,
                                            },
                                            {
                                                label: inv.filters.warehouse_id ? 'Costo unit. (bodega)' : 'Costo unit. (prom.)',
                                                value: (
                                                    <span className="tabular-nums">
                                                        {(() => {
                                                            const cost = inv.filters.warehouse_id
                                                                ? (i.filtered_stock?.current_cost ?? i.current_cost)
                                                                : i.current_cost;
                                                            return Number(cost) > 0 ? formatCurrency(cost) : '—';
                                                        })()}
                                                    </span>
                                                ),
                                            },
                                            {
                                                label: 'Estado',
                                                value: isArchived ? (
                                                    <Badge variant="secondary">Archivado</Badge>
                                                ) : i.is_low_stock ? (
                                                    <Badge variant="warning">Bajo</Badge>
                                                ) : (
                                                    <Badge variant="safe">OK</Badge>
                                                ),
                                                full: false,
                                            },
                                        ]}
                                        footer={
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="sm" className="ml-auto">
                                                        <MoreVertical className="h-4 w-4" />
                                                        Acciones
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    {!isArchived && (
                                                        <>
                                                            <DropdownMenuItem
                                                                onClick={() => {
                                                                    setEntryFor(i);
                                                                    setErrors({});
                                                                }}
                                                            >
                                                                <ArrowUpCircle className="mr-2 h-4 w-4 text-[color:var(--color-status-safe)]" />
                                                                Registrar entrada
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                onClick={() => {
                                                                    setWasteFor(i);
                                                                    setErrors({});
                                                                }}
                                                            >
                                                                <ArrowDownCircle className="text-destructive mr-2 h-4 w-4" />
                                                                Registrar merma
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                onClick={() => {
                                                                    setAdjustFor(i);
                                                                    setErrors({});
                                                                }}
                                                            >
                                                                <Sliders className="mr-2 h-4 w-4" />
                                                                Ajuste manual
                                                            </DropdownMenuItem>
                                                            {hasMultipleWarehouses && (
                                                                <DropdownMenuItem
                                                                    onClick={() => {
                                                                        setTransferIngredientId(i.id);
                                                                        setTransferOpen(true);
                                                                    }}
                                                                >
                                                                    <ArrowLeftRight className="mr-2 h-4 w-4" />
                                                                    Transferir
                                                                </DropdownMenuItem>
                                                            )}
                                                            <DropdownMenuItem
                                                                onClick={() => {
                                                                    setEditing(i);
                                                                    setErrors({});
                                                                    setShowForm(true);
                                                                }}
                                                            >
                                                                <Pencil className="mr-2 h-4 w-4" />
                                                                Editar
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem onClick={() => setConfirmArchive(i)}>
                                                                <Archive className="mr-2 h-4 w-4" />
                                                                Archivar
                                                            </DropdownMenuItem>
                                                        </>
                                                    )}
                                                    <DropdownMenuItem onClick={() => setHistoryFor(i)}>
                                                        <History className="mr-2 h-4 w-4" />
                                                        Historial
                                                    </DropdownMenuItem>
                                                    {isArchived && <DropdownMenuItem onClick={() => doRestore(i)}>Restaurar</DropdownMenuItem>}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        }
                                    />
                                );
                            }}
                        />

                        {/* Desktop: tabla densa */}
                        <div className="hidden sm:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Insumo</TableHead>
                                        <TableHead>Categoría</TableHead>
                                        <TableHead className="text-right">Existencias</TableHead>
                                        <TableHead className="text-right">Mín</TableHead>
                                        <TableHead
                                            className="text-right"
                                            title={
                                                inv.filters.warehouse_id
                                                    ? 'Costo promedio ponderado (WAC) en la bodega seleccionada'
                                                    : 'Costo promedio ponderado (WAC) entre las bodegas de la sede activa'
                                            }
                                        >
                                            Costo unit.
                                        </TableHead>
                                        <TableHead>Estado</TableHead>
                                        <TableHead className="text-right">Acciones</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {inv.ingredients.map((i) => {
                                        const isArchived = i.archived_at !== null;
                                        const stockValue = inv.filters.warehouse_id ? (i.filtered_stock?.quantity ?? '0.000') : i.total_stock;
                                        const minStockValue = inv.filters.warehouse_id
                                            ? (i.filtered_stock?.min_stock ?? '0.000')
                                            : i.stocks.reduce((max, s) => Math.max(max, Number(s.min_stock)), 0).toString();

                                        return (
                                            <TableRow key={i.id}>
                                                <TableCell className="font-medium">
                                                    <div>{i.name}</div>
                                                    {!inv.filters.warehouse_id && i.stocks.length > 1 && (
                                                        <div className="text-muted-foreground mt-0.5 flex flex-wrap gap-1 text-[10px]">
                                                            {i.stocks
                                                                .filter((s) => Number(s.quantity) > 0)
                                                                .map((s) => (
                                                                    <span
                                                                        key={s.warehouse_id}
                                                                        className="bg-muted rounded px-1.5 py-0.5"
                                                                        title={
                                                                            Number(s.current_cost) > 0
                                                                                ? `Costo en ${s.name}: ${formatCurrency(s.current_cost)}`
                                                                                : undefined
                                                                        }
                                                                    >
                                                                        {s.name}:{' '}
                                                                        {Number(s.quantity).toLocaleString('es-CO', {
                                                                            maximumFractionDigits: 3,
                                                                        })}{' '}
                                                                        {i.unit}
                                                                        {Number(s.current_cost) > 0 && (
                                                                            <span className="text-muted-foreground/70">
                                                                                {' '}
                                                                                · {formatCurrency(s.current_cost)}
                                                                            </span>
                                                                        )}
                                                                    </span>
                                                                ))}
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">{i.category ?? '—'}</TableCell>
                                                <TableCell className="text-right tabular-nums">{formatStock(stockValue, i.unit)}</TableCell>
                                                <TableCell className="text-muted-foreground text-right tabular-nums">
                                                    {Number(minStockValue) > 0 ? formatStock(minStockValue, i.unit) : '—'}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {(() => {
                                                        const cost = inv.filters.warehouse_id
                                                            ? (i.filtered_stock?.current_cost ?? i.current_cost)
                                                            : i.current_cost;
                                                        return Number(cost) > 0 ? formatCurrency(cost) : '—';
                                                    })()}
                                                </TableCell>
                                                <TableCell>
                                                    {isArchived ? (
                                                        <Badge variant="secondary">Archivado</Badge>
                                                    ) : i.is_low_stock ? (
                                                        <Badge variant="warning">Bajo</Badge>
                                                    ) : (
                                                        <Badge variant="safe">OK</Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex justify-end gap-1">
                                                        {!isArchived ? (
                                                            <>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    title="Registrar entrada"
                                                                    onClick={() => {
                                                                        setEntryFor(i);
                                                                        setErrors({});
                                                                    }}
                                                                >
                                                                    <ArrowUpCircle className="h-4 w-4 text-[color:var(--color-status-safe)]" />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    title="Registrar merma"
                                                                    onClick={() => {
                                                                        setWasteFor(i);
                                                                        setErrors({});
                                                                    }}
                                                                >
                                                                    <ArrowDownCircle className="text-destructive h-4 w-4" />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    title="Ajuste manual"
                                                                    onClick={() => {
                                                                        setAdjustFor(i);
                                                                        setErrors({});
                                                                    }}
                                                                >
                                                                    <Sliders className="h-4 w-4" />
                                                                </Button>
                                                                {hasMultipleWarehouses && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        title="Transferir entre bodegas"
                                                                        onClick={() => {
                                                                            setTransferIngredientId(i.id);
                                                                            setTransferOpen(true);
                                                                        }}
                                                                    >
                                                                        <ArrowLeftRight className="h-4 w-4" />
                                                                    </Button>
                                                                )}
                                                            </>
                                                        ) : null}
                                                        <Button variant="ghost" size="sm" title="Historial" onClick={() => setHistoryFor(i)}>
                                                            <History className="h-4 w-4" />
                                                        </Button>
                                                        {!isArchived ? (
                                                            <>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    title="Editar"
                                                                    onClick={() => {
                                                                        setEditing(i);
                                                                        setErrors({});
                                                                        setShowForm(true);
                                                                    }}
                                                                >
                                                                    <Pencil className="h-4 w-4" />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    title="Archivar"
                                                                    onClick={() => setConfirmArchive(i)}
                                                                >
                                                                    <Archive className="h-4 w-4" />
                                                                </Button>
                                                            </>
                                                        ) : (
                                                            <Button variant="ghost" size="sm" onClick={() => doRestore(i)}>
                                                                Restaurar
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                ) : null}

                {hasWarehouses && <InventoryValuationChart warehouses={warehouses} selectedWarehouseId={inv.filters.warehouse_id || undefined} />}
            </div>

            <IngredientFormModal
                open={showForm}
                onClose={() => {
                    setShowForm(false);
                    setEditing(null);
                }}
                onSubmit={submitIngredient}
                editing={editing}
                submitting={submitting}
                errors={errors}
            />
            <RecordEntryModal
                open={entryFor !== null}
                onClose={() => setEntryFor(null)}
                onSubmit={submitEntry}
                ingredient={entryFor}
                submitting={submitting}
                errors={errors}
            />
            <RecordWasteModal
                open={wasteFor !== null}
                onClose={() => setWasteFor(null)}
                onSubmit={submitWaste}
                ingredient={wasteFor}
                submitting={submitting}
                errors={errors}
            />
            <AdjustStockModal
                open={adjustFor !== null}
                onClose={() => setAdjustFor(null)}
                onSubmit={submitAdjustment}
                ingredient={adjustFor}
                submitting={submitting}
                errors={errors}
            />
            <MovementsDrawer
                open={historyFor !== null}
                onClose={() => setHistoryFor(null)}
                ingredient={historyFor}
                fetchMovements={inv.fetchMovements}
            />

            <ConfirmDialog
                open={confirmArchive !== null}
                title={`¿Archivar "${confirmArchive?.name}"?`}
                message="El insumo dejará de aparecer en listados activos pero conservará todo su historial. Podrás restaurarlo más adelante."
                confirmLabel="Archivar"
                onConfirm={doArchive}
                onCancel={() => setConfirmArchive(null)}
                loading={archiving}
            />
            <TransferStockModal
                open={transferOpen}
                onClose={() => setTransferOpen(false)}
                warehouses={warehouses}
                ingredients={inv.ingredients}
                initialIngredientId={transferIngredientId}
                onSubmit={async (payload: TransferPayload) => {
                    await inv.transfer(payload);
                    showToast('success', 'Transferencia registrada.');
                    await inv.fetchIngredients();
                }}
            />
        </PageShell>
    );
}
