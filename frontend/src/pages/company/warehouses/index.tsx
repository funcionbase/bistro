import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ListCardSkeleton } from '@/components/ui/list-card-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatTile } from '@/components/ui/stat-tile';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { sanitizePlainText, sanitizeSlug, slugify } from '@/lib/input-sanitize';
import { useSharedData } from '@/lib/shared-data';
import type { Warehouse, WarehouseType } from '@/types/inventory';

import { AlertCircle, Archive, Pencil, Plus, Star, Trash2, Warehouse as WarehouseIcon } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';

interface Branch {
    id: string;
    name: string;
    slug: string;
    is_default: boolean;
}

interface FormState {
    id?: string;
    branch_id: string;
    name: string;
    slug: string;
    type: WarehouseType;
    is_default: boolean;
}

const TYPE_LABELS: Record<WarehouseType, string> = {
    main: 'Principal',
    kitchen: 'Cocina',
    bar: 'Barra',
    cold_storage: 'Congelador',
    dry_storage: 'Bodega seca',
};

const EMPTY_FORM: FormState = {
    branch_id: '',
    name: '',
    slug: '',
    type: 'kitchen',
    is_default: false,
};

const WAREHOUSE_NAME_MAX = 120;
// Espejo del backend WarehouseController: slug `max:64`, regex `^[a-z0-9-]+$`.
const WAREHOUSE_SLUG_MAX = 64;

export default function WarehousesIndex() {
    const token = useToken();
    const { permissions = [], branches: sharedBranches = [] } = useSharedData();
    const canManage = permissions.includes('warehouses.manage');

    const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [showArchived, setShowArchived] = useState(false);
    const [selectedBranchId, setSelectedBranchId] = useState<string | 'all'>('all');

    const [modalOpen, setModalOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    // Mientras el slug no se edite a mano, se autosugiere desde el nombre.
    const [slugTouched, setSlugTouched] = useState(false);

    const [confirmArchive, setConfirmArchive] = useState<Warehouse | null>(null);
    const [archiving, setArchiving] = useState(false);

    // Asignación de sedes por bodega.
    const [assignTarget, setAssignTarget] = useState<Warehouse | null>(null);
    const [assignBranchId, setAssignBranchId] = useState('');
    const [assignAsDefault, setAssignAsDefault] = useState(false);
    const [assigning, setAssigning] = useState(false);
    const [assignError, setAssignError] = useState<string | null>(null);
    const [confirmUnassign, setConfirmUnassign] = useState<{ warehouse: Warehouse; branchId: string } | null>(null);
    const [unassigning, setUnassigning] = useState(false);
    const [busyBranchKey, setBusyBranchKey] = useState<string | null>(null);

    useEffect(() => {
        if (!token) return;
        void load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [token, showArchived, selectedBranchId]);

    async function load() {
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams();
            if (showArchived) params.set('include_archived', '1');
            if (selectedBranchId !== 'all') params.set('branch_id', selectedBranchId);
            const res = await apiFetch(`/api/v1/company/warehouses${params.toString() ? '?' + params.toString() : ''}`);
            const json = (await res.json()) as { data: Warehouse[]; message?: string };
            if (!res.ok) throw new Error(json.message ?? 'Error al cargar bodegas.');
            setWarehouses(json.data);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'No se pudieron cargar las bodegas.');
        } finally {
            setLoading(false);
        }
    }

    const branches = useMemo<Branch[]>(() => sharedBranches as Branch[], [sharedBranches]);
    const branchById = useMemo(() => {
        const map = new Map<string, Branch>();
        for (const b of branches) map.set(b.id, b);
        return map;
    }, [branches]);

    const filteredWarehouses = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return warehouses;
        return warehouses.filter(
            (w) => w.name.toLowerCase().includes(q) || w.slug.toLowerCase().includes(q) || TYPE_LABELS[w.type].toLowerCase().includes(q),
        );
    }, [warehouses, search]);

    const kpis = useMemo(() => {
        let active = 0;
        let archived = 0;
        const coveredBranches = new Set<string>();
        for (const w of warehouses) {
            if (w.archived_at) {
                archived += 1;
            } else {
                active += 1;
                for (const b of w.branches) coveredBranches.add(b.branch_id);
            }
        }
        return {
            total: warehouses.length,
            active,
            archived,
            coveredBranches: coveredBranches.size,
        };
    }, [warehouses]);

    function openCreate(branchId?: string) {
        setForm({ ...EMPTY_FORM, branch_id: branchId ?? branches[0]?.id ?? '' });
        setFormError(null);
        setSlugTouched(false);
        setModalOpen(true);
    }

    function openEdit(w: Warehouse) {
        setForm({
            id: w.id,
            branch_id: '',
            name: w.name,
            slug: w.slug,
            type: w.type,
            is_default: w.is_default,
        });
        setFormError(null);
        // En edición ya hay slug propio: no lo pisamos al tocar el nombre.
        setSlugTouched(true);
        setModalOpen(true);
    }

    const submit: FormEventHandler<HTMLFormElement> = async (e) => {
        e.preventDefault();
        if (!token) return;
        setSaving(true);
        setFormError(null);

        try {
            const isEdit = !!form.id;
            const url = isEdit ? `/api/v1/company/warehouses/${form.id}` : '/api/v1/company/warehouses';
            const body = isEdit
                ? {
                      name: form.name,
                      slug: form.slug || undefined,
                      type: form.type,
                  }
                : {
                      // En creación, asignar opcionalmente a una sede inicial.
                      branch_id: form.branch_id || undefined,
                      name: form.name,
                      slug: form.slug || undefined,
                      type: form.type,
                      is_default: form.branch_id ? form.is_default : undefined,
                  };

            const res = await apiFetch(url, {
                method: isEdit ? 'PATCH' : 'POST',
                body: JSON.stringify(body),
                headers: { 'Content-Type': 'application/json' },
            });
            if (!res.ok) {
                const errJson = await res.json();
                throw errJson;
            }

            setModalOpen(false);
            await load();
        } catch (err) {
            const apiErr = err as { errors?: Record<string, string[]>; message?: string };
            const first = apiErr.errors ? Object.values(apiErr.errors)[0]?.[0] : apiErr.message;
            setFormError(first ?? 'No se pudo guardar la bodega.');
        } finally {
            setSaving(false);
        }
    };

    async function archive() {
        if (!token || !confirmArchive) return;
        setArchiving(true);
        try {
            const res = await apiFetch(`/api/v1/company/warehouses/${confirmArchive.id}`, {
                method: 'DELETE',
            });
            if (!res.ok) {
                const json = await res.json();
                throw new Error(json.message ?? 'No se pudo archivar.');
            }
            setConfirmArchive(null);
            await load();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'No se pudo archivar la bodega.');
        } finally {
            setArchiving(false);
        }
    }

    function applyUpdatedWarehouse(updated: Warehouse) {
        setWarehouses((prev) => prev.map((w) => (w.id === updated.id ? updated : w)));
        setAssignTarget((prev) => (prev && prev.id === updated.id ? updated : prev));
    }

    function openAssign(w: Warehouse) {
        setAssignTarget(w);
        setAssignBranchId('');
        setAssignAsDefault(false);
        setAssignError(null);
    }

    async function assignBranch() {
        if (!token || !assignTarget || !assignBranchId) return;
        setAssigning(true);
        setAssignError(null);
        try {
            const res = await apiFetch(`/api/v1/company/warehouses/${assignTarget.id}/branches`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ branch_id: assignBranchId, is_default: assignAsDefault }),
            });
            const json = await res.json();
            if (!res.ok) throw new Error(json.message ?? 'No se pudo asignar la sede.');
            applyUpdatedWarehouse(json.data as Warehouse);
            setAssignBranchId('');
            setAssignAsDefault(false);
        } catch (err) {
            setAssignError(err instanceof Error ? err.message : 'No se pudo asignar la sede.');
        } finally {
            setAssigning(false);
        }
    }

    async function setBranchDefault(w: Warehouse, branchId: string) {
        if (!token) return;
        const key = `${w.id}:${branchId}`;
        setBusyBranchKey(key);
        setAssignError(null);
        try {
            const res = await apiFetch(`/api/v1/company/warehouses/${w.id}/branches/${branchId}/default`, {
                method: 'PUT',
            });
            const json = await res.json();
            if (!res.ok) throw new Error(json.message ?? 'No se pudo marcar como predeterminada.');
            applyUpdatedWarehouse(json.data as Warehouse);
        } catch (err) {
            setAssignError(err instanceof Error ? err.message : 'No se pudo marcar como predeterminada.');
        } finally {
            setBusyBranchKey(null);
        }
    }

    async function unassignBranch() {
        if (!token || !confirmUnassign) return;
        setUnassigning(true);
        setAssignError(null);
        try {
            const res = await apiFetch(`/api/v1/company/warehouses/${confirmUnassign.warehouse.id}/branches/${confirmUnassign.branchId}`, {
                method: 'DELETE',
            });
            const json = await res.json();
            if (!res.ok) {
                const code = (json as { code?: string }).code;
                const msg =
                    code === 'WAREHOUSE_USED_BY_RECIPES'
                        ? 'No se puede desasignar: hay recetas de esa sede que costean desde esta bodega.'
                        : (json as { message?: string }).message ?? 'No se pudo desasignar la sede.';
                throw new Error(msg);
            }
            applyUpdatedWarehouse(json.data as Warehouse);
            setConfirmUnassign(null);
        } catch (err) {
            setAssignError(err instanceof Error ? err.message : 'No se pudo desasignar la sede.');
        } finally {
            setUnassigning(false);
        }
    }

    const hasActiveFilters = search.length > 0 || showArchived || selectedBranchId !== 'all';
    const noBranches = branches.length === 0;

    const unassignedBranches = useMemo(() => {
        if (!assignTarget) return branches;
        const assigned = new Set(assignTarget.branches.map((b) => b.branch_id));
        return branches.filter((b) => !assigned.has(b.id));
    }, [assignTarget, branches]);

    return (
        <PageShell title="Bodegas">
            <div className="flex flex-col gap-6 p-4 lg:p-6">
                <PageHeader
                    eyebrow="CATÁLOGO"
                    title="Bodegas"
                    description="Subdivisiones de inventario de la empresa: cocina, barra, congelador, bodega seca. Cada bodega se asigna a una o varias sedes."
                    actions={
                        canManage && (
                            <Button onClick={() => openCreate()} disabled={noBranches}>
                                <Plus className="mr-1 h-4 w-4" /> Nueva bodega
                            </Button>
                        )
                    }
                />

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <StatTile size="lg" value={kpis.total} label="Bodegas totales" />
                    <StatTile size="lg" value={kpis.active} label="Activas" tone={kpis.active > 0 ? 'safe' : 'default'} />
                    <StatTile size="lg" value={kpis.archived} label="Archivadas" />
                    <StatTile size="lg" value={`${kpis.coveredBranches}/${branches.length}`} label="Sedes con bodega" />
                </div>

                <FilterBar variant="card" searchValue={search} onSearchChange={setSearch} searchPlaceholder="Buscar por nombre, slug o tipo…">
                    <div className="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-2">
                        <Label htmlFor="branch-filter" className="text-muted-foreground text-xs sm:text-sm">
                            Sede:
                        </Label>
                        <Select value={selectedBranchId} onValueChange={(v) => setSelectedBranchId(v as 'all' | string)}>
                            <SelectTrigger id="branch-filter" className="w-full sm:w-40">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todas</SelectItem>
                                {branches.map((b) => (
                                    <SelectItem key={b.id} value={b.id}>
                                        {b.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox id="warehouse-archived" checked={showArchived} onCheckedChange={(v) => setShowArchived(v === true)} />
                        Incluir archivadas
                    </label>
                </FilterBar>

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {noBranches && !loading && (
                    <Alert variant="warning">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>
                            Aún no tienes sedes registradas. Crea una sede primero en Configuración → Sedes antes de configurar bodegas.
                        </AlertDescription>
                    </Alert>
                )}

                {loading ? (
                    <ListCardSkeleton rows={4} actions={2} variant="card" gridClassName="grid gap-3 md:grid-cols-2 lg:grid-cols-3" />
                ) : filteredWarehouses.length === 0 ? (
                    <Card>
                        <CardContent className="p-0">
                            <EmptyState
                                icon={WarehouseIcon}
                                title={hasActiveFilters ? 'Sin bodegas con los filtros actuales' : 'Aún no hay bodegas configuradas'}
                                description={
                                    hasActiveFilters
                                        ? 'Ajusta el buscador, cambia de sede o desactiva el filtro de archivadas.'
                                        : 'Crea la primera bodega para subdividir el inventario de tus sedes (cocina, barra, congelador).'
                                }
                                action={
                                    !hasActiveFilters &&
                                    canManage &&
                                    !noBranches && (
                                        <Button onClick={() => openCreate()}>
                                            <Plus className="mr-1 h-4 w-4" /> Nueva bodega
                                        </Button>
                                    )
                                }
                            />
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        {filteredWarehouses.map((w) => (
                            <Card key={w.id}>
                                <CardHeader className="flex flex-row items-start justify-between gap-3 pb-2">
                                    <div className="min-w-0 space-y-1.5">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <WarehouseIcon className="text-muted-foreground h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span className="truncate">{w.name}</span>
                                        </CardTitle>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {w.archived_at && <Badge variant="secondary">Archivada</Badge>}
                                            <span className="text-muted-foreground text-xs">{TYPE_LABELS[w.type] ?? w.type}</span>
                                        </div>
                                    </div>
                                    {canManage && !w.archived_at && (
                                        <div className="flex shrink-0 gap-1">
                                            <Button variant="outline" size="sm" onClick={() => openEdit(w)} aria-label={`Editar ${w.name}`}>
                                                <Pencil className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setConfirmArchive(w)}
                                                aria-label={`Archivar ${w.name}`}
                                            >
                                                <Archive className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    )}
                                </CardHeader>
                                <CardContent className="space-y-3 pt-0 text-sm">
                                    <p className="text-muted-foreground font-mono text-xs">slug: {w.slug}</p>

                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Sedes asignadas</span>
                                            {canManage && !w.archived_at && (
                                                <Button variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={() => openAssign(w)}>
                                                    <Plus className="mr-1 h-3 w-3" /> Asignar
                                                </Button>
                                            )}
                                        </div>
                                        {w.branches.length === 0 ? (
                                            <p className="text-muted-foreground text-xs">Sin sedes asignadas.</p>
                                        ) : (
                                            <ul className="space-y-1">
                                                {w.branches.map((assignment) => {
                                                    const key = `${w.id}:${assignment.branch_id}`;
                                                    const branchName = branchById.get(assignment.branch_id)?.name ?? assignment.branch_id;
                                                    return (
                                                        <li
                                                            key={assignment.branch_id}
                                                            className="bg-muted/40 flex items-center justify-between gap-2 rounded-md px-2 py-1"
                                                        >
                                                            <span className="flex min-w-0 items-center gap-1.5">
                                                                <span className="truncate">{branchName}</span>
                                                                {assignment.is_default && (
                                                                    <Badge variant="accent" className="gap-1">
                                                                        <Star className="h-3 w-3 fill-current" aria-hidden="true" />
                                                                        Predet.
                                                                    </Badge>
                                                                )}
                                                            </span>
                                                            {canManage && !w.archived_at && (
                                                                <span className="flex shrink-0 items-center gap-1">
                                                                    {!assignment.is_default && (
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="sm"
                                                                            className="h-6 px-2 text-xs"
                                                                            disabled={busyBranchKey === key}
                                                                            onClick={() => setBranchDefault(w, assignment.branch_id)}
                                                                            title="Marcar como predeterminada de la sede"
                                                                        >
                                                                            <Star className="h-3 w-3" />
                                                                        </Button>
                                                                    )}
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        className="text-destructive hover:text-destructive h-6 w-6 px-0"
                                                                        onClick={() =>
                                                                            setConfirmUnassign({ warehouse: w, branchId: assignment.branch_id })
                                                                        }
                                                                        aria-label={`Desasignar ${branchName}`}
                                                                    >
                                                                        <Trash2 className="h-3 w-3" />
                                                                    </Button>
                                                                </span>
                                                            )}
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            <Dialog open={modalOpen} onOpenChange={(v) => !v && setModalOpen(false)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{form.id ? 'Editar bodega' : 'Nueva bodega'}</DialogTitle>
                        <DialogDescription>
                            Las bodegas pertenecen a la empresa y se asignan a una o varias sedes. La predeterminada de cada sede recibe los consumos
                            cuando no se especifica otra.
                        </DialogDescription>
                    </DialogHeader>

                    <form noValidate onSubmit={submit} className="space-y-4">
                        {!form.id && (
                            <div className="space-y-1.5">
                                <Label htmlFor="form-branch">Asignar a sede (opcional)</Label>
                                <Select value={form.branch_id || 'none'} onValueChange={(v) => setForm((f) => ({ ...f, branch_id: v === 'none' ? '' : v }))}>
                                    <SelectTrigger id="form-branch">
                                        <SelectValue placeholder="Selecciona…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No asignar todavía</SelectItem>
                                        {branches.map((b) => (
                                            <SelectItem key={b.id} value={b.id}>
                                                {b.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-muted-foreground text-xs">Podrás asignarla a más sedes después de crearla.</p>
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="form-name">Nombre</Label>
                            <Input
                                id="form-name"
                                required
                                value={form.name}
                                onChange={(e) => {
                                    const name = sanitizePlainText(e.target.value, WAREHOUSE_NAME_MAX, false, false);
                                    // Autosugiere el slug desde el nombre hasta que se edite a mano.
                                    setForm((f) => ({ ...f, name, slug: slugTouched ? f.slug : slugify(name, WAREHOUSE_SLUG_MAX) }));
                                }}
                                placeholder="Cocina caliente"
                                maxLength={WAREHOUSE_NAME_MAX}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="form-slug">Slug (opcional)</Label>
                            <Input
                                id="form-slug"
                                value={form.slug}
                                onChange={(e) => {
                                    setForm((f) => ({ ...f, slug: sanitizeSlug(e.target.value, WAREHOUSE_SLUG_MAX) }));
                                    setSlugTouched(true);
                                }}
                                placeholder="cocina-caliente"
                                pattern="^[a-z0-9-]+$"
                                maxLength={WAREHOUSE_SLUG_MAX}
                            />
                            <p className="text-muted-foreground text-xs">
                                Solo minúsculas, números y guiones. Se genera automáticamente si lo dejas vacío.
                            </p>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="form-type">Tipo</Label>
                            <Select value={form.type} onValueChange={(v) => setForm((f) => ({ ...f, type: v as WarehouseType }))}>
                                <SelectTrigger id="form-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(Object.keys(TYPE_LABELS) as WarehouseType[]).map((t) => (
                                        <SelectItem key={t} value={t}>
                                            {TYPE_LABELS[t]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {!form.id && form.branch_id && (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    id="warehouse-is-default"
                                    checked={form.is_default}
                                    onCheckedChange={(v) => setForm((f) => ({ ...f, is_default: v === true }))}
                                />
                                Bodega por defecto de la sede seleccionada
                            </label>
                        )}

                        {formError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{formError}</AlertDescription>
                            </Alert>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setModalOpen(false)} disabled={saving}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {saving ? 'Guardando…' : form.id ? 'Guardar' : 'Crear bodega'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!assignTarget} onOpenChange={(v) => !v && setAssignTarget(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Asignar sede</DialogTitle>
                        <DialogDescription>
                            Asigna “{assignTarget?.name}” a una sede. Podrás marcarla como predeterminada para que reciba los consumos por defecto.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="assign-branch">Sede</Label>
                            <Select value={assignBranchId} onValueChange={setAssignBranchId} disabled={unassignedBranches.length === 0}>
                                <SelectTrigger id="assign-branch">
                                    <SelectValue placeholder={unassignedBranches.length === 0 ? 'Todas las sedes ya están asignadas' : 'Selecciona…'} />
                                </SelectTrigger>
                                <SelectContent>
                                    {unassignedBranches.map((b) => (
                                        <SelectItem key={b.id} value={b.id}>
                                            {b.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                id="assign-default"
                                checked={assignAsDefault}
                                onCheckedChange={(v) => setAssignAsDefault(v === true)}
                            />
                            Marcar como predeterminada de la sede
                        </label>

                        {assignError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{assignError}</AlertDescription>
                            </Alert>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setAssignTarget(null)} disabled={assigning}>
                            Cerrar
                        </Button>
                        <Button type="button" onClick={assignBranch} disabled={assigning || !assignBranchId}>
                            {assigning ? 'Asignando…' : 'Asignar sede'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={!!confirmArchive}
                title="Archivar bodega"
                message={`Vas a archivar "${confirmArchive?.name}". Su histórico de movimientos se conserva; podrás restaurarla después.`}
                confirmLabel="Archivar"
                loading={archiving}
                onConfirm={archive}
                onCancel={() => setConfirmArchive(null)}
            />

            <ConfirmDialog
                open={!!confirmUnassign}
                title="Desasignar sede"
                message={`Vas a quitar la sede "${
                    confirmUnassign ? branchById.get(confirmUnassign.branchId)?.name ?? confirmUnassign.branchId : ''
                }" de la bodega "${confirmUnassign?.warehouse.name ?? ''}". Si hay recetas de esa sede que costean desde esta bodega, no se podrá desasignar.`}
                confirmLabel="Desasignar"
                loading={unassigning}
                onConfirm={unassignBranch}
                onCancel={() => setConfirmUnassign(null)}
            />
        </PageShell>
    );
}
