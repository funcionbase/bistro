import { CloneMenuButton } from '@/components/branches/clone-menu-button';
import MenuCard from '@/components/menu/menu-card';
import PublishModal from '@/components/menu/publish-modal';
import ScheduleModal from '@/components/menu/schedule-modal';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MenusListSkeleton } from '@/components/ui/menus-list-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { useToast } from '@/components/ui/toast';
import { useActiveBranch } from '@/hooks/use-active-branch';
import { usePermissions } from '@/hooks/use-permissions';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { sanitizePlainText } from '@/lib/input-sanitize';
import type { RestaurantMenu } from '@/types';

import { AlertCircle, BookOpen, LoaderCircle, Lock, Plus, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';


interface NewMenuForm {
    name: string;
    description: string;
}

export default function MenuIndex() {
    const activeToken = useToken();
    const { showToast } = useToast();
    const { has } = usePermissions();
    const canCreate = has('menu.create');
    const canUpdate = has('menu.update');
    const canDelete = has('menu.delete');
    const canCloneMenu = has('branches.copy_menu');
    const { activeBranch, branches } = useActiveBranch();
    const [menus, setMenus] = useState<RestaurantMenu[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState<NewMenuForm>({ name: '', description: '' });
    const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
    const [submitting, setSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState<string | null>(null);
    const [duplicatingId, setDuplicatingId] = useState<string | null>(null);
    const [showPublishModal, setShowPublishModal] = useState(false);
    const [selectedMenuForPublish, setSelectedMenuForPublish] = useState<RestaurantMenu | null>(null);
    const [isPublishing, setIsPublishing] = useState(false);
    const [deactivatingId, setDeactivatingId] = useState<string | null>(null);
    const [menuToDeactivate, setMenuToDeactivate] = useState<RestaurantMenu | null>(null);
    const [menuToDelete, setMenuToDelete] = useState<RestaurantMenu | null>(null);
    const [selectedMenuForSchedule, setSelectedMenuForSchedule] = useState<RestaurantMenu | null>(null);
    const [isSyncing, setIsSyncing] = useState(false);
    const isMounted = useRef(true);

    const fetchMenus = useCallback(async () => {
        if (!activeToken) return;
        try {
            const res = await apiFetch('/api/v1/menus');
            const data = await res.json();
            if (!isMounted.current) return;
            if (!res.ok) {
                setError(data.message ?? 'Error al cargar menús.');
                return;
            }
            setMenus(data.data ?? []);
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [activeToken]);

    useEffect(() => {
        isMounted.current = true;
        fetchMenus();
        return () => {
            isMounted.current = false;
        };
    }, [fetchMenus]);

    async function handleCreate(e: React.FormEvent) {
        e.preventDefault();
        setFormErrors({});
        setSubmitting(true);
        try {
            const res = await apiFetch('/api/v1/menus', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: form.name, description: form.description || null }),
            });
            const data = await res.json();
            if (!res.ok) {
                setFormErrors(data.errors ?? { name: [data.message ?? 'Error.'] });
                return;
            }
            setMenus((prev) => [...prev, data.data]);
            setShowForm(false);
            setForm({ name: '', description: '' });
        } catch {
            setFormErrors({ name: ['Error de conexión.'] });
        } finally {
            setSubmitting(false);
        }
    }

    function handleOpenPublishModal(menu: RestaurantMenu) {
        setSelectedMenuForPublish(menu);
        setShowPublishModal(true);
    }

    async function handlePublish() {
        if (!selectedMenuForPublish) return;
        setIsPublishing(true);
        try {
            const res = await apiFetch(`/api/v1/menus/${selectedMenuForPublish.id}/activate`, { method: 'PATCH' });
            if (res.ok) {
                await fetchMenus();
                setShowPublishModal(false);
                setSelectedMenuForPublish(null);
            } else {
                const data = await res.json();
                showToast('error', data.message || 'No se pudo publicar el menú.');
            }
        } catch {
            showToast('error', 'Error de conexión al publicar.');
        } finally {
            setIsPublishing(false);
        }
    }

    async function confirmDeactivate() {
        if (!menuToDeactivate) return;
        const menu = menuToDeactivate;
        setDeactivatingId(menu.id);
        try {
            const res = await apiFetch(`/api/v1/menus/${menu.id}/deactivate`, { method: 'PATCH' });
            if (res.ok) {
                await fetchMenus();
                setMenuToDeactivate(null);
            } else {
                const data = await res.json().catch(() => ({}));
                showToast('error', data.message || 'No se pudo desactivar el menú.');
            }
        } catch {
            showToast('error', 'Error de conexión al desactivar.');
        } finally {
            setDeactivatingId(null);
        }
    }

    async function confirmDelete() {
        if (!menuToDelete) return;
        const target = menuToDelete;
        setDeletingId(target.id);
        try {
            const res = await apiFetch(`/api/v1/menus/${target.id}`, { method: 'DELETE' });
            if (res.ok) {
                setMenus((prev) => prev.filter((m) => m.id !== target.id));
                setMenuToDelete(null);
            } else {
                const data = await res.json().catch(() => ({}));
                showToast('error', data.message || 'No se pudo eliminar el menú.');
            }
        } catch {
            showToast('error', 'Error de conexión al eliminar.');
        } finally {
            setDeletingId(null);
        }
    }

    async function handleDuplicate(menu: RestaurantMenu) {
        setDuplicatingId(menu.id);
        try {
            const res = await apiFetch(`/api/v1/menus/${menu.id}/duplicate`, { method: 'POST' });
            const data = await res.json();
            if (!res.ok) {
                showToast('error', data.message ?? 'No se pudo duplicar el menú.');
                return;
            }
            setMenus((prev) => [...prev, data.data]);
        } catch {
            showToast('error', 'Error de conexión al duplicar.');
        } finally {
            setDuplicatingId(null);
        }
    }

    async function handleSyncSchedule() {
        setIsSyncing(true);
        try {
            const res = await apiFetch('/api/v1/menus/sync-schedule', { method: 'POST' });
            if (res.ok) {
                await fetchMenus();
            } else {
                const data = await res.json();
                showToast('error', data.message || 'No se pudo sincronizar la programación.');
            }
        } catch {
            showToast('error', 'Error de conexión al sincronizar.');
        } finally {
            setIsSyncing(false);
        }
    }

    function handleScheduleSaved(updated: RestaurantMenu) {
        setMenus((prev) => prev.map((m) => (m.id === updated.id ? updated : m)));
        setSelectedMenuForSchedule(null);
        fetchMenus();
    }

    return (
        <PageShell title="Gestión de Menú">
            <div className="space-y-6 p-4 sm:p-6">
                {loading ? (
                    <MenusListSkeleton cards={6} />
                ) : (
                    <>
                        <PageHeader
                            eyebrow="Menú"
                            title="Menús de la sede"
                            description={
                                canUpdate ? 'Administra los menús, categorías y platos de esta sede.' : 'Consulta los menús, categorías y platos de esta sede.'
                            }
                            actions={
                                <>
                                    {canUpdate && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={handleSyncSchedule}
                                            disabled={isSyncing}
                                            className="gap-2"
                                            title="Aplica la programación de menús y refresca la lista"
                                        >
                                            <RefreshCw className={`h-4 w-4 ${isSyncing ? 'animate-spin' : ''}`} />
                                            Sincronizar menús
                                        </Button>
                                    )}
                                    {canCreate && (
                                        <Button onClick={() => setShowForm((v) => !v)} className="gap-2">
                                            <Plus className="h-4 w-4" />
                                            Crear menú
                                        </Button>
                                    )}
                                </>
                            }
                        />

                        {!canUpdate && (
                            <Alert variant="warning">
                                <Lock className="h-4 w-4" />
                                <AlertDescription>Vista de solo lectura: tu rol no permite editar menús, categorías ni platos.</AlertDescription>
                            </Alert>
                        )}

                        {showForm && (
                            <Card>
                                <CardContent className="pt-5">
                                    <form noValidate onSubmit={handleCreate} className="space-y-3">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="menu-name">Nombre del menú *</Label>
                                            <Input
                                                id="menu-name"
                                                value={form.name}
                                                onChange={(e) => setForm((f) => ({ ...f, name: sanitizePlainText(e.target.value, 128, false, false) }))}
                                                placeholder="Ej. Menú almuerzo"
                                                maxLength={128}
                                            />
                                            {formErrors.name?.[0] && <p className="text-destructive text-sm">{formErrors.name[0]}</p>}
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="menu-desc">Descripción</Label>
                                            <Input
                                                id="menu-desc"
                                                value={form.description}
                                                onChange={(e) =>
                                                    setForm((f) => ({ ...f, description: sanitizePlainText(e.target.value, 512, true, false) }))
                                                }
                                                placeholder="Descripción opcional"
                                                maxLength={512}
                                            />
                                        </div>
                                        <div className="flex gap-2">
                                            <Button type="submit" disabled={submitting}>
                                                {submitting ? <LoaderCircle className="h-4 w-4 animate-spin" /> : 'Crear menú'}
                                            </Button>
                                            <Button type="button" variant="outline" onClick={() => setShowForm(false)}>
                                                Cancelar
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        )}

                        {error ? (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        ) : menus.length === 0 ? (
                            <EditorialEmpty
                                eyebrow="Empezar"
                                icon={<BookOpen className="size-12" />}
                                title="Esta sede aún no tiene menú"
                                description="Crea uno desde cero o clona el menú de otra sede como punto de partida. Después de clonar, los menús quedan independientes."
                                action={
                                    <div className="flex flex-col items-center gap-2 sm:flex-row">
                                        {canCreate && (
                                            <Button onClick={() => setShowForm(true)} size="lg">
                                                <Plus className="mr-1 h-4 w-4" /> Crear menú
                                            </Button>
                                        )}
                                        {activeBranch && (
                                            <CloneMenuButton
                                                branches={branches}
                                                currentBranchId={activeBranch.id}
                                                canCopy={canCloneMenu}
                                                onCopied={() => void fetchMenus()}
                                            />
                                        )}
                                    </div>
                                }
                            />
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {menus.map((menu) => (
                                    <MenuCard
                                        key={menu.id}
                                        menu={menu}
                                        onPublish={canUpdate ? () => handleOpenPublishModal(menu) : undefined}
                                        onDeactivate={canUpdate ? () => setMenuToDeactivate(menu) : undefined}
                                        onDuplicate={canCreate ? () => handleDuplicate(menu) : undefined}
                                        onDelete={canDelete ? () => setMenuToDelete(menu) : undefined}
                                        onSchedule={canUpdate ? () => setSelectedMenuForSchedule(menu) : undefined}
                                        isDuplicating={duplicatingId === menu.id}
                                        isPublishing={isPublishing && selectedMenuForPublish?.id === menu.id}
                                        isDeactivating={deactivatingId === menu.id}
                                        isDeleting={deletingId === menu.id}
                                    />
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>

            {selectedMenuForPublish && (
                <PublishModal
                    isOpen={showPublishModal}
                    menu={selectedMenuForPublish}
                    onConfirm={handlePublish}
                    onCancel={() => {
                        setShowPublishModal(false);
                        setSelectedMenuForPublish(null);
                    }}
                    isLoading={isPublishing}
                />
            )}

            {selectedMenuForSchedule && (
                <ScheduleModal menu={selectedMenuForSchedule} onClose={() => setSelectedMenuForSchedule(null)} onSaved={handleScheduleSaved} />
            )}

            <ConfirmDialog
                open={menuToDeactivate !== null}
                title="¿Desactivar este menú?"
                message={
                    menuToDeactivate
                        ? `"${menuToDeactivate.name}" pasará a borrador y dejará de servirse en esta sede.`
                        : ''
                }
                confirmLabel="Desactivar"
                loading={deactivatingId === menuToDeactivate?.id}
                onConfirm={() => void confirmDeactivate()}
                onCancel={() => setMenuToDeactivate(null)}
            />

            <ConfirmDialog
                open={menuToDelete !== null}
                title="¿Eliminar este menú?"
                message={
                    menuToDelete
                        ? `Se eliminará "${menuToDelete.name}". Esta acción no se puede deshacer.`
                        : ''
                }
                confirmLabel="Eliminar"
                loading={deletingId === menuToDelete?.id}
                onConfirm={() => void confirmDelete()}
                onCancel={() => setMenuToDelete(null)}
            />
        </PageShell>
    );
}
