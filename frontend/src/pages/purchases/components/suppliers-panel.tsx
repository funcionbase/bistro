import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DataCard, DataCardList } from '@/components/ui/data-card-list';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { StatTile } from '@/components/ui/stat-tile';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToast } from '@/components/ui/toast';
import type { useSuppliers } from '@/hooks/use-suppliers';
import type { Supplier, SupplierFormPayload } from '@/types/suppliers';

import { AlertCircle, Archive, Pencil, Plus, RotateCcw, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { SupplierFormModal } from './supplier-form-modal';

interface Props {
    /** Instancia compartida con la pestaña de órdenes — un solo fetch de catálogo. */
    sup: ReturnType<typeof useSuppliers>;
    canCreate: boolean;
}

/**
 * Pestaña "Proveedores" de la página unificada de Compras (#fusión
 * /suppliers → /purchases). Es el contenido completo de la antigua página
 * `/suppliers` sin PageShell/PageHeader propios.
 */
export function SuppliersPanel({ sup, canCreate }: Props) {
    const { showToast } = useToast();

    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState<Supplier | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [confirmArchive, setConfirmArchive] = useState<Supplier | null>(null);

    const kpis = useMemo(() => {
        const all = sup.suppliers;
        let active = 0;
        let archived = 0;
        let termsSum = 0;
        let termsCount = 0;
        for (const s of all) {
            if (s.archived_at) {
                archived += 1;
            } else {
                active += 1;
                termsSum += s.payment_terms_days;
                termsCount += 1;
            }
        }
        const avgTerms = termsCount > 0 ? Math.round(termsSum / termsCount) : 0;
        return { total: all.length, active, archived, avgTerms };
    }, [sup.suppliers]);

    function handleApiError(err: unknown, fallback: string) {
        const apiErr = err as { errors?: Record<string, string[]>; message?: string };
        if (apiErr?.errors) setErrors(apiErr.errors);
        else showToast('error', apiErr?.message ?? fallback);
    }

    function openCreate() {
        setEditing(null);
        setErrors({});
        setShowForm(true);
    }

    async function submitSupplier(payload: SupplierFormPayload) {
        setSubmitting(true);
        setErrors({});
        try {
            if (editing) {
                await sup.updateSupplier(editing.id, payload);
                showToast('success', `"${payload.name}" actualizado.`);
            } else {
                await sup.createSupplier(payload);
                showToast('success', `"${payload.name}" creado.`);
            }
            setShowForm(false);
            setEditing(null);
            await sup.fetchSuppliers();
        } catch (err) {
            handleApiError(err, 'No se pudo guardar el proveedor.');
        } finally {
            setSubmitting(false);
        }
    }

    async function archive() {
        if (!confirmArchive) return;
        try {
            await sup.archiveSupplier(confirmArchive.id);
            showToast('success', `"${confirmArchive.name}" archivado.`);
            setConfirmArchive(null);
            await sup.fetchSuppliers();
        } catch (err) {
            handleApiError(err, 'No se pudo archivar.');
        }
    }

    async function restore(s: Supplier) {
        try {
            await sup.restoreSupplier(s.id);
            showToast('success', `"${s.name}" restaurado.`);
            await sup.fetchSuppliers();
        } catch (err) {
            handleApiError(err, 'No se pudo restaurar.');
        }
    }

    const hasActiveFilters = sup.filters.q.length > 0 || sup.filters.archived;

    return (
        <div className="flex flex-col gap-6">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <StatTile size="lg" value={kpis.total} label="Proveedores totales" />
                <StatTile size="lg" value={kpis.active} label="Activos" tone={kpis.active > 0 ? 'safe' : 'default'} />
                <StatTile size="lg" value={kpis.archived} label="Archivados" />
                <StatTile size="lg" value={`${kpis.avgTerms} d`} label="Plazo promedio de pago" />
            </div>

            <FilterBar
                variant="card"
                searchValue={sup.filters.q}
                onSearchChange={(value) => sup.setFilters({ q: value })}
                searchPlaceholder="Buscar por nombre, NIT, contacto…"
            >
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={sup.filters.archived}
                        onChange={(e) => sup.setFilters({ archived: e.target.checked })}
                    />
                    Mostrar archivados
                </label>
                {canCreate && (
                    <Button size="sm" onClick={openCreate} className="ml-auto">
                        <Plus className="mr-1 h-4 w-4" /> Crear proveedor
                    </Button>
                )}
            </FilterBar>

            {sup.error && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertDescription>{sup.error}</AlertDescription>
                </Alert>
            )}

            {sup.suppliers.length === 0 ? (
                <EmptyState
                    icon={Truck}
                    title={hasActiveFilters ? 'Sin proveedores con los filtros actuales' : 'Aún no hay proveedores'}
                    description={
                        hasActiveFilters
                            ? 'Ajusta el buscador o desactiva el filtro de archivados.'
                            : 'Agrega tu primer proveedor para empezar a registrar órdenes de compra.'
                    }
                    action={
                        !hasActiveFilters &&
                        canCreate && (
                            <Button onClick={openCreate}>
                                <Plus className="mr-1 h-4 w-4" /> Crear proveedor
                            </Button>
                        )
                    }
                />
            ) : (
                <>
                    {/* Mobile: card-stack */}
                    <DataCardList
                        items={sup.suppliers}
                        getKey={(s) => s.id}
                        className="sm:hidden"
                        renderCard={(s) => (
                            <DataCard
                                title={
                                    <span className="flex items-center gap-2">
                                        <span className="truncate">{s.name}</span>
                                        {s.archived_at && <Badge variant="secondary">Archivado</Badge>}
                                    </span>
                                }
                                subtitle={
                                    s.document_type ? (
                                        <span>
                                            <span className="mr-1 text-[10px] font-medium uppercase">{s.document_type}</span>
                                            <span className="tabular-nums">{s.document_number ?? '—'}</span>
                                        </span>
                                    ) : undefined
                                }
                                fields={[
                                    { label: 'Contacto', value: s.contact_name ?? '—' },
                                    { label: 'Teléfono', value: s.phone ?? '—' },
                                    {
                                        label: 'Email',
                                        value: s.email ?? '—',
                                        full: true,
                                    },
                                    { label: 'Plazo', value: <span className="tabular-nums">{s.payment_terms_days} d</span> },
                                ]}
                                footer={
                                    <div className="flex w-full items-center justify-end gap-1">
                                        {s.archived_at ? (
                                            <Button size="sm" variant="outline" onClick={() => restore(s)}>
                                                <RotateCcw className="mr-1 h-3.5 w-3.5" /> Restaurar
                                            </Button>
                                        ) : (
                                            <>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => {
                                                        setEditing(s);
                                                        setErrors({});
                                                        setShowForm(true);
                                                    }}
                                                    aria-label={`Editar ${s.name}`}
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => setConfirmArchive(s)}
                                                    aria-label={`Archivar ${s.name}`}
                                                >
                                                    <Archive className="h-3.5 w-3.5" />
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                }
                            />
                        )}
                    />

                    {/* Desktop: tabla densa */}
                    <div className="hidden sm:block">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nombre</TableHead>
                                    <TableHead>Documento</TableHead>
                                    <TableHead>Contacto</TableHead>
                                    <TableHead>Teléfono</TableHead>
                                    <TableHead className="text-right">Plazo</TableHead>
                                    <TableHead className="text-right">Acciones</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sup.suppliers.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell className="font-medium">
                                            <div className="flex items-center gap-2">
                                                <span>{s.name}</span>
                                                {s.archived_at && <Badge variant="secondary">Archivado</Badge>}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {s.document_type ? (
                                                <>
                                                    <span className="text-muted-foreground mr-1 text-xs font-medium uppercase">
                                                        {s.document_type}
                                                    </span>
                                                    <span className="tabular-nums">{s.document_number ?? '—'}</span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {s.contact_name ?? '—'}
                                            {s.email && <div className="text-muted-foreground truncate text-xs">{s.email}</div>}
                                        </TableCell>
                                        <TableCell>{s.phone ?? '—'}</TableCell>
                                        <TableCell className="text-right tabular-nums">{s.payment_terms_days} d</TableCell>
                                        <TableCell className="text-right">
                                            {s.archived_at ? (
                                                <Button size="sm" variant="outline" onClick={() => restore(s)}>
                                                    <RotateCcw className="mr-1 h-3.5 w-3.5" /> Restaurar
                                                </Button>
                                            ) : (
                                                <div className="inline-flex gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => {
                                                            setEditing(s);
                                                            setErrors({});
                                                            setShowForm(true);
                                                        }}
                                                        aria-label={`Editar ${s.name}`}
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => setConfirmArchive(s)}
                                                        aria-label={`Archivar ${s.name}`}
                                                    >
                                                        <Archive className="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </>
            )}

            <SupplierFormModal
                open={showForm}
                onClose={() => {
                    setShowForm(false);
                    setEditing(null);
                }}
                onSubmit={submitSupplier}
                editing={editing}
                submitting={submitting}
                errors={errors}
            />

            <ConfirmDialog
                open={!!confirmArchive}
                title="Archivar proveedor"
                message={`Vas a archivar "${confirmArchive?.name}". Sus órdenes históricas se conservan; podrás restaurarlo después.`}
                confirmLabel="Archivar"
                onConfirm={archive}
                onCancel={() => setConfirmArchive(null)}
            />
        </div>
    );
}
