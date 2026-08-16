import { BusinessTypeSelector } from '@/components/business-type-selector';
import InputError from '@/components/input-error';
import { MunicipalityCombobox } from '@/components/clients/municipality-combobox';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { FieldHint } from '@/components/ui/field-hint';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ListCardSkeleton } from '@/components/ui/list-card-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ui/toast';
import { useBusinessTypes } from '@/hooks/use-business-types';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { sanitizeSlug, slugify } from '@/lib/input-sanitize';
import { reloadContext } from '@/lib/navigate-compat';
import { useSharedData } from '@/lib/shared-data';
import { BranchMenuBranding } from '@/components/company/branch-menu-branding';
import { Archive, Copy, Landmark, LoaderCircle, MapPin, Palette, Pencil, Plus, Star, Users } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';

interface Branch {
    id: string;
    name: string;
    slug: string;
    address: string | null;
    city: string | null;
    municipality_dane_code: string | null;
    municipality_label: string | null;
    business_type_id: string | null;
    is_default: boolean;
    archived_at: string | null;
    created_at: string | null;
}

interface BranchUser {
    id: string;
    name: string;
    email: string;
    granted_at: string | null;
}

interface FormState {
    id?: string;
    name: string;
    slug: string;
    address: string;
    city: string;
    municipality_dane_code: string | null;
    municipality_label: string | null;
    business_type_id: string;
    initial_business_type_id: string | null;
    is_default: boolean;
}

interface BranchCashRegister {
    id: string;
    name: string;
    is_active: boolean;
    sort_order: number;
    archived: boolean;
}

const EMPTY_FORM: FormState = {
    name: '',
    slug: '',
    address: '',
    city: '',
    municipality_dane_code: null,
    municipality_label: null,
    business_type_id: 'restaurant',
    initial_business_type_id: null,
    is_default: false,
};

// Espejo del backend StoreBranchRequest: slug `max:60`, regex `^[a-z0-9-]+$`.
const SLUG_MAX = 60;


export default function BranchesIndex() {
    const token = useToken();
    const { showToast } = useToast();
    const { permissions = [] } = useSharedData();
    const canManage = permissions.includes('branches.manage');
    const canAssignUsers = permissions.includes('branches.assign_users');
    const canCopyMenu = permissions.includes('branches.copy_menu');

    const [branches, setBranches] = useState<Branch[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [showArchived, setShowArchived] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    // Errores 422 por campo → inline bajo cada input. `formError` queda para
    // errores no atribuibles a un campo.
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    // Mientras el slug no se edite a mano, se autosugiere desde el nombre.
    const [slugTouched, setSlugTouched] = useState(false);

    const [usersModalBranch, setUsersModalBranch] = useState<Branch | null>(null);
    const [users, setUsers] = useState<BranchUser[]>([]);
    const [usersLoading, setUsersLoading] = useState(false);

    const [copyModalTarget, setCopyModalTarget] = useState<Branch | null>(null);
    const [copySource, setCopySource] = useState<string>('');
    const [copying, setCopying] = useState(false);

    const [confirmArchive, setConfirmArchive] = useState<Branch | null>(null);
    const [archiving, setArchiving] = useState(false);

    const [cashModalBranch, setCashModalBranch] = useState<Branch | null>(null);
    const [cashRegisters, setCashRegisters] = useState<BranchCashRegister[]>([]);
    const [cashLoading, setCashLoading] = useState(false);
    const [cashNewName, setCashNewName] = useState('');
    const [cashSaving, setCashSaving] = useState(false);

    const [brandingModalBranch, setBrandingModalBranch] = useState<Branch | null>(null);

    // Labels de business types para mostrar como badge en cada sede.
    const businessTypesQuery = useBusinessTypes();
    const businessTypeLabels = useMemo(() => {
        const map = new Map<string, string>();
        for (const t of businessTypesQuery.data ?? []) {
            map.set(t.slug, t.label_es);
        }
        return map;
    }, [businessTypesQuery.data]);

    useEffect(() => {
        if (!token) return;
        void load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [token, showArchived]);

    async function load() {
        setLoading(true);
        try {
            const res = await apiFetch(`/api/v1/company/branches?include_archived=${showArchived ? 1 : 0}`);
            const data = await res.json();
            if (!res.ok) throw new Error(data.message ?? 'Error');
            setBranches(data.data ?? []);
            setError(null);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Error de conexión');
        } finally {
            setLoading(false);
        }
    }

    function openCreate() {
        setForm(EMPTY_FORM);
        setFormError(null);
        setFieldErrors({});
        setSlugTouched(false);
        setModalOpen(true);
    }

    function openEdit(b: Branch) {
        setForm({
            id: b.id,
            name: b.name,
            slug: b.slug,
            address: b.address ?? '',
            city: b.city ?? '',
            municipality_dane_code: b.municipality_dane_code,
            municipality_label: b.municipality_label,
            business_type_id: b.business_type_id ?? 'restaurant',
            initial_business_type_id: b.business_type_id,
            is_default: b.is_default,
        });
        setFormError(null);
        setFieldErrors({});
        // En edición ya hay slug propio: no lo pisamos al tocar el nombre.
        setSlugTouched(true);
        setModalOpen(true);
    }

    const submitForm: FormEventHandler = async (e) => {
        e.preventDefault();
        setSaving(true);
        setFormError(null);
        setFieldErrors({});
        try {
            const isEdit = !!form.id;
            const url = isEdit ? `/api/v1/company/branches/${form.id}` : `/api/v1/company/branches`;
            const baseBody: Record<string, unknown> = {
                name: form.name,
                slug: form.slug,
                address: form.address || null,
                city: form.city || null,
                municipality_dane_code: form.municipality_dane_code,
                is_default: form.is_default,
            };
            // En create incluimos business_type_id; en edit el vertical va por
            // endpoint dedicado que sembra prep_areas faltantes.
            if (!isEdit) {
                baseBody.business_type_id = form.business_type_id;
            }
            const res = await apiFetch(url, {
                method: isEdit ? 'PATCH' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(baseBody),
            });
            const data = await res.json();
            if (!res.ok) {
                // 422: errores por campo → inline bajo cada input.
                if (res.status === 422 && data.errors) {
                    const mapped: Record<string, string> = {};
                    for (const [field, messages] of Object.entries(data.errors as Record<string, string[]>)) {
                        mapped[field] = messages[0] ?? '';
                    }
                    setFieldErrors(mapped);
                    return;
                }
                throw new Error(data.message ?? 'No se pudo guardar la sede.');
            }

            // #237 — si el vertical cambió en edit, dispara el endpoint dedicado.
            if (isEdit && form.initial_business_type_id !== form.business_type_id) {
                const btRes = await apiFetch(`/api/v1/company/branches/${form.id}/change-business-type`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ business_type_id: form.business_type_id }),
                });
                const btData = await btRes.json();
                if (!btRes.ok) {
                    throw new Error(btData.message ?? 'No se pudo cambiar el tipo de negocio.');
                }
            }

            setModalOpen(false);
            showToast('success', isEdit ? 'Sede actualizada.' : 'Sede creada.');
            await load();
            // Refresca shared props (`branches`, `activeBranch`) para que el
            // BranchSwitcher del sidebar muestre la sede recién creada/editada.
            reloadContext();
        } catch (e) {
            setFormError(e instanceof Error ? e.message : 'Error de conexión');
        } finally {
            setSaving(false);
        }
    };

    async function confirmArchiveAction() {
        if (!confirmArchive) return;
        setArchiving(true);
        try {
            const res = await apiFetch(`/api/v1/company/branches/${confirmArchive.id}`, { method: 'DELETE' });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message ?? 'No se pudo archivar.');
            showToast('success', `Sede "${confirmArchive.name}" archivada.`);
            setConfirmArchive(null);
            await load();
            reloadContext();
        } catch (e) {
            showToast('error', e instanceof Error ? e.message : 'Error de conexión');
        } finally {
            setArchiving(false);
        }
    }

    async function openUsersModal(b: Branch) {
        setUsersModalBranch(b);
        setUsersLoading(true);
        try {
            const res = await apiFetch(`/api/v1/company/branches/${b.id}/users`);
            const data = await res.json();
            setUsers(data.data ?? []);
        } finally {
            setUsersLoading(false);
        }
    }

    async function copyMenu() {
        if (!copyModalTarget || !copySource) return;
        setCopying(true);
        try {
            const res = await apiFetch(`/api/v1/company/branches/${copyModalTarget.id}/menu/copy`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ source_branch_id: copySource }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message ?? 'No se pudo copiar.');
            showToast('success', `Menú copiado: ${data.data.items_count} ítems.`);
            setCopyModalTarget(null);
            setCopySource('');
        } catch (e) {
            showToast('error', e instanceof Error ? e.message : 'Error de conexión');
        } finally {
            setCopying(false);
        }
    }

    async function openCashModal(b: Branch) {
        setCashModalBranch(b);
        setCashNewName('');
        setCashLoading(true);
        try {
            const res = await apiFetch(`/api/v1/company/branches/${b.id}/cash-registers`);
            const data = await res.json();
            setCashRegisters(data.data ?? []);
        } finally {
            setCashLoading(false);
        }
    }

    async function addCashRegister() {
        if (!cashModalBranch || !cashNewName.trim()) return;
        setCashSaving(true);
        try {
            const res = await apiFetch(`/api/v1/company/branches/${cashModalBranch.id}/cash-registers`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: cashNewName.trim() }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message ?? 'No se pudo crear la caja.');
            setCashRegisters((prev) => [...prev, data.data]);
            setCashNewName('');
        } catch (e) {
            showToast('error', e instanceof Error ? e.message : 'Error de conexión');
        } finally {
            setCashSaving(false);
        }
    }

    async function archiveCashRegister(r: BranchCashRegister) {
        if (!cashModalBranch) return;
        try {
            const res = await apiFetch(`/api/v1/company/branches/${cashModalBranch.id}/cash-registers/${r.id}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ archived: true }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message ?? 'No se pudo archivar.');
            setCashRegisters((prev) => prev.map((x) => (x.id === r.id ? data.data : x)));
        } catch (e) {
            showToast('error', e instanceof Error ? e.message : 'Error de conexión');
        }
    }

    return (
        <PageShell title="Sedes">
            <div className="container mx-auto space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="SEDES"
                    title="Sedes"
                    description="Cada sede tiene su propio inventario, caja, cupones y reportes. Los datos no se cruzan entre sedes."
                    variant="editorial"
                    actions={
                        <>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox id="show_archived" checked={showArchived} onCheckedChange={(v) => setShowArchived(v === true)} />
                                <span className="text-muted-foreground">Ver archivadas</span>
                            </label>
                            {canManage && (
                                <Button onClick={openCreate} className="w-full sm:w-auto">
                                    <Plus className="mr-2 size-4" /> Nueva sede
                                </Button>
                            )}
                        </>
                    }
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <DashboardPanel title="Listado de sedes" icon={MapPin}>
                    {loading ? (
                        <ListCardSkeleton rows={3} actions={3} responsive />
                    ) : branches.length === 0 ? (
                        <EmptyState
                            icon={MapPin}
                            title="No hay sedes registradas"
                            description="Las sedes te permiten operar varias direcciones bajo la misma empresa, cada una con su menú y horarios."
                            action={
                                canManage ? (
                                    <Button onClick={openCreate}>
                                        <Plus className="mr-2 size-4" /> Crear primera sede
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <>
                            {/* Móvil + tablet (<lg): tarjeta por sede con acciones que envuelven
                                (touch-target ≥40px). La lista densa necesita ancho para 4 acciones
                                por fila, así que solo aparece en lg+. */}
                            <ul className="space-y-3 lg:hidden">
                                {branches.map((b) => (
                                    <li key={b.id} className="border-border bg-card space-y-3 rounded-2xl border p-4">
                                        <div className="flex items-start gap-3">
                                            <div className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                                                <MapPin className="size-5" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <p className="truncate font-medium">{b.name}</p>
                                                    {b.is_default && (
                                                        <Badge variant="warning" className="gap-1">
                                                            <Star className="size-3 fill-current" /> Principal
                                                        </Badge>
                                                    )}
                                                    {b.business_type_id && businessTypeLabels.has(b.business_type_id) && (
                                                        <Badge variant="outline">{businessTypeLabels.get(b.business_type_id)}</Badge>
                                                    )}
                                                    {b.archived_at && <Badge variant="secondary">Archivada</Badge>}
                                                </div>
                                                <p className="text-muted-foreground mt-0.5 truncate text-xs">
                                                    {[b.address, b.city].filter(Boolean).join(' · ') || `slug: ${b.slug}`}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {canAssignUsers && (
                                                <Button variant="outline" size="sm" onClick={() => openUsersModal(b)}>
                                                    <Users className="mr-1 size-4" /> Usuarios
                                                </Button>
                                            )}
                                            {canManage && !b.archived_at && (
                                                <Button variant="outline" size="sm" onClick={() => openCashModal(b)}>
                                                    <Landmark className="mr-1 size-4" /> Cajas
                                                </Button>
                                            )}
                                            {canManage && !b.archived_at && (
                                                <Button variant="outline" size="sm" onClick={() => setBrandingModalBranch(b)}>
                                                    <Palette className="mr-1 size-4" /> Diseño menú
                                                </Button>
                                            )}
                                            {canCopyMenu && !b.archived_at && (
                                                <Button variant="outline" size="sm" onClick={() => setCopyModalTarget(b)}>
                                                    <Copy className="mr-1 size-4" /> Copiar menú
                                                </Button>
                                            )}
                                            {canManage && !b.archived_at && (
                                                <>
                                                    <Button variant="outline" size="sm" onClick={() => openEdit(b)}>
                                                        <Pencil className="mr-1 size-4" /> Editar
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => setConfirmArchive(b)}
                                                        className="text-muted-foreground hover:text-destructive"
                                                    >
                                                        <Archive className="mr-1 size-4" /> Archivar
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>

                            {/* Desktop (lg+): lista densa con divider — hay ancho para las 4 acciones en fila. */}
                            <div className="divide-border hidden divide-y lg:block">
                                {branches.map((b) => (
                                    <div key={b.id} className="flex items-center justify-between gap-4 py-3">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <div className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                                                <MapPin className="size-5" />
                                            </div>
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="truncate font-medium">{b.name}</p>
                                                    {b.is_default && (
                                                        <Badge variant="warning" className="gap-1">
                                                            <Star className="size-3 fill-current" /> Principal
                                                        </Badge>
                                                    )}
                                                    {b.business_type_id && businessTypeLabels.has(b.business_type_id) && (
                                                        <Badge variant="outline">{businessTypeLabels.get(b.business_type_id)}</Badge>
                                                    )}
                                                    {b.archived_at && <Badge variant="secondary">Archivada</Badge>}
                                                </div>
                                                <p className="text-muted-foreground truncate text-xs">
                                                    {[b.address, b.city].filter(Boolean).join(' · ') || `slug: ${b.slug}`}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex shrink-0 gap-1">
                                            {canAssignUsers && (
                                                <Button variant="ghost" size="sm" onClick={() => openUsersModal(b)}>
                                                    <Users className="mr-1 size-4" /> Usuarios
                                                </Button>
                                            )}
                                            {canManage && !b.archived_at && (
                                                <Button variant="ghost" size="sm" onClick={() => openCashModal(b)} title="Cajas">
                                                    <Landmark className="mr-1 size-4" /> Cajas
                                                </Button>
                                            )}
                                            {canManage && !b.archived_at && (
                                                <Button variant="ghost" size="sm" onClick={() => setBrandingModalBranch(b)} title="Diseño menú">
                                                    <Palette className="mr-1 size-4" /> Diseño
                                                </Button>
                                            )}
                                            {canCopyMenu && !b.archived_at && (
                                                <Button variant="ghost" size="sm" onClick={() => setCopyModalTarget(b)}>
                                                    <Copy className="mr-1 size-4" /> Copiar menú
                                                </Button>
                                            )}
                                            {canManage && !b.archived_at && (
                                                <>
                                                    <Button variant="ghost" size="sm" onClick={() => openEdit(b)} title="Editar">
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                    <Button variant="ghost" size="sm" onClick={() => setConfirmArchive(b)} title="Archivar">
                                                        <Archive className="size-4" />
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </DashboardPanel>
            </div>

            <Dialog open={modalOpen} onOpenChange={(o) => !o && setModalOpen(false)}>
                {/* sm:max-w-2xl — el BusinessTypeSelector necesita ancho para
                    que las cards de vertical no compriman los labels largos
                    ("Cajero de mostrador", "Bartender de cocina"). */}
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{form.id ? 'Editar sede' : 'Nueva sede'}</DialogTitle>
                    </DialogHeader>
                    <form noValidate onSubmit={submitForm} className="space-y-4">
                        {formError && (
                            <Alert variant="destructive">
                                <AlertDescription>{formError}</AlertDescription>
                            </Alert>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nombre</Label>
                            <Input
                                id="name"
                                required
                                value={form.name}
                                onChange={(e) => {
                                    const name = e.target.value;
                                    // Autosugiere el slug desde el nombre hasta que el
                                    // usuario lo edite a mano.
                                    setForm((f) => ({ ...f, name, slug: slugTouched ? f.slug : slugify(name, SLUG_MAX) }));
                                }}
                                aria-invalid={!!fieldErrors.name}
                            />
                            <InputError message={fieldErrors.name} className="text-xs" />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="slug">Slug (identificador URL)</Label>
                            <Input
                                id="slug"
                                required
                                pattern="[a-z0-9-]+"
                                maxLength={SLUG_MAX}
                                placeholder="ej. centro, norte, parque-93"
                                value={form.slug}
                                onChange={(e) => {
                                    setForm((f) => ({ ...f, slug: sanitizeSlug(e.target.value, SLUG_MAX) }));
                                    setSlugTouched(true);
                                }}
                                aria-invalid={!!fieldErrors.slug}
                            />
                            {fieldErrors.slug ? (
                                <InputError message={fieldErrors.slug} className="text-xs" />
                            ) : (
                                <p className="text-muted-foreground text-xs">Solo minúsculas, números y guiones. Único por empresa.</p>
                            )}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="address">Dirección</Label>
                                <Input
                                    id="address"
                                    value={form.address}
                                    onChange={(e) => setForm({ ...form, address: e.target.value })}
                                    aria-invalid={!!fieldErrors.address}
                                />
                                <InputError message={fieldErrors.address} className="text-xs" />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="city">Ciudad</Label>
                                <MunicipalityCombobox
                                    id="city"
                                    value={form.municipality_dane_code}
                                    label={form.municipality_label}
                                    onChange={(code, label) =>
                                        setForm({ ...form, municipality_dane_code: code, municipality_label: label, city: label ? label.split(',')[0] : form.city })
                                    }
                                    placeholder="Buscá la ciudad de la sede…"
                                />
                                <p className="text-muted-foreground text-xs">Los domicilios de esta sede se entregan solo en esta ciudad.</p>
                                <InputError message={fieldErrors.municipality_dane_code} className="text-xs" />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <div className="flex items-center gap-2">
                                <Label>Tipo de negocio</Label>
                                <FieldHint>
                                    <p className="leading-snug">
                                        Define qué módulos quedan habilitados para esta sede (mesas, KDS, domicilios, recetas, etc.) y siembra sus
                                        áreas de preparación por defecto.
                                    </p>
                                    {form.id && (
                                        <p className="leading-snug opacity-80">
                                            Cambiarlo <strong>no afecta</strong> órdenes históricas, menús ni receipts — sólo qué módulos aparecen y
                                            agrega áreas de preparación faltantes.
                                        </p>
                                    )}
                                </FieldHint>
                            </div>
                            <BusinessTypeSelector
                                value={form.business_type_id}
                                onChange={(slug) => setForm({ ...form, business_type_id: slug })}
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="is_default"
                                checked={form.is_default}
                                onCheckedChange={(v) => setForm({ ...form, is_default: v === true })}
                            />
                            <Label htmlFor="is_default" className="cursor-pointer text-sm font-normal">
                                Marcar como sede principal (informativo)
                            </Label>
                        </div>
                        <DialogFooter className="gap-2 sm:gap-2">
                            <Button type="button" variant="outline" onClick={() => setModalOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {saving && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                                {saving ? 'Guardando...' : 'Guardar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!usersModalBranch} onOpenChange={(o) => !o && setUsersModalBranch(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Usuarios — {usersModalBranch?.name}</DialogTitle>
                    </DialogHeader>
                    {usersLoading ? (
                        <Skeleton className="h-32 w-full" />
                    ) : (
                        <div className="space-y-2">
                            {users.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Aún no hay usuarios asignados a esta sede.</p>
                            ) : (
                                users.map((u) => (
                                    <div key={u.id} className="border-border flex items-center justify-between border-b py-2">
                                        <div>
                                            <p className="text-sm font-medium">{u.name}</p>
                                            <p className="text-muted-foreground text-xs">{u.email}</p>
                                        </div>
                                    </div>
                                ))
                            )}
                            <p className="text-muted-foreground pt-2 text-xs">Para asignar/quitar usuarios usa la página de Usuarios → Sedes.</p>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={!!copyModalTarget}
                onOpenChange={(o) => {
                    if (!o) {
                        setCopyModalTarget(null);
                        setCopySource('');
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Copiar menú a {copyModalTarget?.name}</DialogTitle>
                        <DialogDescription>
                            Selecciona la sede origen del menú activo. Se creará una copia <strong>draft</strong> en la sede destino. Tras la copia
                            los menús son independientes.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="copy_source">Sede origen</Label>
                        <Select value={copySource} onValueChange={setCopySource}>
                            <SelectTrigger id="copy_source">
                                <SelectValue placeholder="Selecciona..." />
                            </SelectTrigger>
                            <SelectContent>
                                {branches
                                    .filter((b) => b.id !== copyModalTarget?.id && !b.archived_at)
                                    .map((b) => (
                                        <SelectItem key={b.id} value={b.id}>
                                            {b.name}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button variant="outline" onClick={() => setCopyModalTarget(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={copyMenu} disabled={!copySource || copying}>
                            {copying && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                            {copying ? 'Copiando...' : 'Copiar menú'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={!!cashModalBranch} onOpenChange={(o) => !o && setCashModalBranch(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cajas — {cashModalBranch?.name}</DialogTitle>
                        <DialogDescription>
                            Cada caja es un punto de venta independiente con su propia sesión y historial.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        {cashLoading ? (
                            <Skeleton className="h-24 w-full" />
                        ) : cashRegisters.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Esta sede aún no tiene cajas configuradas.</p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {cashRegisters.map((r) => (
                                    <li key={r.id} className="flex items-center justify-between gap-2 py-2">
                                        <div className="min-w-0">
                                            <p className={`truncate text-sm font-medium ${r.archived ? 'text-muted-foreground line-through' : ''}`}>
                                                {r.name}
                                            </p>
                                            {r.archived && (
                                                <p className="text-muted-foreground text-xs">Archivada</p>
                                            )}
                                        </div>
                                        {!r.archived && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-muted-foreground hover:text-destructive shrink-0"
                                                onClick={() => archiveCashRegister(r)}
                                                title="Archivar caja"
                                            >
                                                <Archive className="size-4" />
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="flex gap-2 pt-1">
                            <Input
                                placeholder="Nombre de la nueva caja"
                                maxLength={120}
                                value={cashNewName}
                                onChange={(e) => setCashNewName(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && void addCashRegister()}
                            />
                            <Button onClick={() => void addCashRegister()} disabled={!cashNewName.trim() || cashSaving}>
                                {cashSaving ? <LoaderCircle className="size-4 animate-spin" /> : <Plus className="size-4" />}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={!!brandingModalBranch} onOpenChange={(o) => !o && setBrandingModalBranch(null)}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Diseño del menú — {brandingModalBranch?.name}</DialogTitle>
                    </DialogHeader>
                    {brandingModalBranch && <BranchMenuBranding branchId={brandingModalBranch.id} />}
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={!!confirmArchive}
                title="Archivar sede"
                message={`¿Archivar la sede "${confirmArchive?.name ?? ''}"? Las sedes archivadas no se pueden eliminar para preservar el histórico contable.`}
                confirmLabel="Archivar"
                loading={archiving}
                onConfirm={confirmArchiveAction}
                onCancel={() => setConfirmArchive(null)}
            />
        </PageShell>
    );
}
