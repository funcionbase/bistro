import { PageShell } from '@/components/page-shell';
import RoleBadge from '@/components/role-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DataCard, DataCardList } from '@/components/ui/data-card-list';
import { EditorialEmpty } from '@/components/ui/editorial-empty';
import { PageHeader } from '@/components/ui/page-header';
import { RolesTableSkeleton } from '@/components/ui/roles-table-skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToast } from '@/components/ui/toast';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import type { CompanyRole, Feature } from '@/types';

import { AlertCircle, Lock, Pencil, Plus, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import RoleEditor from './role-editor';


export default function Roles() {
    const activeToken = useToken();
    const { activeCompany } = useSharedData();
    const { showToast } = useToast();

    const [roles, setRoles] = useState<CompanyRole[]>([]);
    const [features, setFeatures] = useState<Feature[]>([]);
    const [canManage, setCanManage] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [editingRole, setEditingRole] = useState<CompanyRole | null>(null);
    const [showEditor, setShowEditor] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState<CompanyRole | null>(null);
    const [deleting, setDeleting] = useState(false);

    const isMounted = useRef(true);

    const fetchData = async () => {
        setLoading(true);
        setError(null);
        try {
            const [rolesRes, featuresRes] = await Promise.all([apiFetch('/api/v1/roles'), apiFetch('/api/v1/features')]);

            if (!rolesRes.ok || !featuresRes.ok) {
                if (!isMounted.current) return;
                setError('No pudimos cargar los roles. Reintenta en unos segundos.');
                return;
            }

            const [rolesData, featuresData] = await Promise.all([rolesRes.json(), featuresRes.json()]);

            if (!isMounted.current) return;

            setRoles(rolesData.data ?? []);
            setCanManage(rolesData.can_manage ?? false);
            setFeatures(featuresData.data ?? []);
        } catch {
            if (!isMounted.current) return;
            setError('Error de conexión. Verifica tu red e intenta de nuevo.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    };

    useEffect(() => {
        isMounted.current = true;

        if (!activeToken) {
            const urlToken = new URLSearchParams(window.location.search).get('token');
            if (urlToken) localStorage.setItem('token', urlToken);
        }

        fetchData();

        return () => {
            isMounted.current = false;
        };
    }, [activeToken]);

    useEffect(() => {
        setRoles([]);
        setFeatures([]);
        setLoading(true);
        setError(null);
    }, [activeCompany?.nit]);

    const handleEdit = (role: CompanyRole) => {
        setEditingRole(role);
        setShowEditor(true);
    };

    const handleNew = () => {
        setEditingRole(null);
        setShowEditor(true);
    };

    const handleClose = () => {
        setShowEditor(false);
        setEditingRole(null);
    };

    const handleSaved = (mode: 'created' | 'updated') => {
        handleClose();
        showToast('success', mode === 'created' ? 'Rol creado' : 'Rol actualizado');
        fetchData();
    };

    const handleConfirmDelete = async () => {
        if (!confirmDelete) return;
        setDeleting(true);
        try {
            const res = await apiFetch(`/api/v1/roles/${confirmDelete.id}`, { method: 'DELETE' });
            if (res.ok) {
                setRoles((prev) => prev.filter((r) => r.id !== confirmDelete.id));
                showToast('success', 'Rol eliminado');
                setConfirmDelete(null);
            } else {
                const data = await res.json().catch(() => ({}));
                showToast('error', data.message ?? 'No se pudo eliminar el rol.');
            }
        } catch {
            showToast('error', 'Error de conexión. Intenta de nuevo.');
        } finally {
            setDeleting(false);
        }
    };

    const headerActions = (
        <>
            <Button variant="outline" size="sm" onClick={fetchData} disabled={loading} title="Actualizar">
                <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                Actualizar
            </Button>
            {canManage && (
                <Button variant="default" onClick={handleNew}>
                    <Plus className="h-4 w-4" />
                    Crear rol
                </Button>
            )}
        </>
    );

    const totalRoles = roles.length;
    const systemRoles = roles.filter((r) => r.is_system).length;
    const customRoles = totalRoles - systemRoles;
    const totalUsers = roles.reduce((acc, r) => acc + (r.users_count ?? 0), 0);

    const renderUsersCount = (count: number) => (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium tabular-nums ${
                count > 0 ? 'bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe)]' : 'bg-muted text-muted-foreground'
            }`}
        >
            {count}
        </span>
    );

    const renderRowActions = (role: CompanyRole) => {
        if (!canManage) return null;
        if (role.is_system) {
            return <span className="text-muted-foreground text-xs">Protegido</span>;
        }
        return (
            <>
                <Button size="icon" variant="ghost" onClick={() => handleEdit(role)} title="Editar rol" className="text-muted-foreground">
                    <Pencil className="h-4 w-4" />
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    onClick={() => setConfirmDelete(role)}
                    title="Eliminar rol"
                    className="text-destructive hover:text-destructive"
                >
                    <Trash2 className="h-4 w-4" />
                </Button>
            </>
        );
    };

    if (loading && roles.length === 0) {
        return (
            <PageShell title="Roles">
                <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                    <PageHeader
                        eyebrow="IDENTIDADES"
                        title="Roles"
                        description="Define qué puede ver y hacer cada miembro de tu equipo."
                        actions={headerActions}
                    />
                    <RolesTableSkeleton showActions={canManage} />
                </div>
            </PageShell>
        );
    }

    return (
        <PageShell title="Roles">
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="IDENTIDADES"
                    title="Roles"
                    description="Define qué puede ver y hacer cada miembro de tu equipo."
                    actions={headerActions}
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {!canManage && !loading && (
                    <Alert variant="warning">
                        <Lock className="h-4 w-4" />
                        <AlertDescription>Solo lectura. Pídele a un administrador permisos sobre roles para crear o editar.</AlertDescription>
                    </Alert>
                )}

                {totalRoles > 0 && (
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                        <Card className="rounded-lg p-4 shadow-sm">
                            <p className="text-muted-foreground text-[11px] font-semibold tracking-[0.15em] uppercase">Roles</p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">{totalRoles}</p>
                        </Card>
                        <Card className="rounded-lg p-4 shadow-sm">
                            <p className="text-muted-foreground text-[11px] font-semibold tracking-[0.15em] uppercase">Personalizados</p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">{customRoles}</p>
                        </Card>
                        <Card className="col-span-2 rounded-lg p-4 shadow-sm md:col-span-1">
                            <p className="text-muted-foreground text-[11px] font-semibold tracking-[0.15em] uppercase">Usuarios asignados</p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">{totalUsers}</p>
                        </Card>
                    </div>
                )}

                {totalRoles === 0 && !loading && !error ? (
                    <EditorialEmpty
                        eyebrow="Empezar"
                        icon={<ShieldCheck className="h-10 w-10" />}
                        title="Aún no tienes roles personalizados"
                        description="Crea perfiles como Cajero, Mesero o Cocina con permisos a la medida. Cada miembro de tu equipo verá solo lo que necesita."
                        action={
                            canManage ? (
                                <Button variant="default" size="lg" onClick={handleNew}>
                                    <Plus className="h-4 w-4" />
                                    Crear el primer rol
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <>
                        {/* Mobile: card-stack (sin overflow horizontal forzado, §10) */}
                        <DataCardList
                            items={roles}
                            getKey={(role) => role.id}
                            className="sm:hidden"
                            renderCard={(role) => {
                                const usersCount = role.users_count ?? 0;
                                const permsCount = (role.permissions ?? []).filter((p) => p.can_create || p.can_read || p.can_update || p.can_delete).length;
                                return (
                                    <DataCard
                                        title={
                                            <div className="flex max-w-full min-w-0 flex-wrap items-center gap-2">
                                                <RoleBadge name={role.name} color={role.color} isSystem={role.is_system} />
                                                {role.is_system && (
                                                    <span className="bg-secondary text-secondary-foreground inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-[0.15em] uppercase">
                                                        Sistema
                                                    </span>
                                                )}
                                            </div>
                                        }
                                        subtitle={role.description ?? '—'}
                                        fields={[
                                            { label: 'Permisos', value: <span className="tabular-nums">{permsCount}</span> },
                                            { label: 'Usuarios', value: renderUsersCount(usersCount) },
                                        ]}
                                        actions={canManage ? <div className="flex items-center gap-1">{renderRowActions(role)}</div> : undefined}
                                    />
                                );
                            }}
                        />

                        {/* Desktop: tabla densa */}
                        <div className="hidden sm:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Nombre</TableHead>
                                        <TableHead>Descripción</TableHead>
                                        <TableHead className="text-right">Permisos</TableHead>
                                        <TableHead>Usuarios</TableHead>
                                        {canManage && <TableHead className="text-right">Acciones</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {roles.map((role) => {
                                        const usersCount = role.users_count ?? 0;
                                        const permsCount = (role.permissions ?? []).filter((p) => p.can_create || p.can_read || p.can_update || p.can_delete).length;
                                        return (
                                            <TableRow key={role.id}>
                                                <TableCell>
                                                    <div className="flex max-w-[260px] min-w-0 items-center gap-2">
                                                        <RoleBadge name={role.name} color={role.color} isSystem={role.is_system} />
                                                        {role.is_system && (
                                                            <span className="bg-secondary text-secondary-foreground inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-[0.15em] uppercase">
                                                                Sistema
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground max-w-md">
                                                    <span className="line-clamp-2">{role.description ?? '—'}</span>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-right tabular-nums">{permsCount}</TableCell>
                                                <TableCell>{renderUsersCount(usersCount)}</TableCell>
                                                {canManage && (
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-1">{renderRowActions(role)}</div>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}
            </div>

            {showEditor && <RoleEditor role={editingRole} features={features} existingRoles={roles} onClose={handleClose} onSaved={handleSaved} />}

            <ConfirmDialog
                open={confirmDelete !== null}
                title="Eliminar rol"
                message={
                    confirmDelete
                        ? (confirmDelete.users_count ?? 0) > 0
                            ? `¿Eliminar el rol "${confirmDelete.name}"? Tiene ${confirmDelete.users_count} usuario(s) asignado(s). Tendrás que reasignarlos antes.`
                            : `¿Eliminar el rol "${confirmDelete.name}"? Esta acción no se puede deshacer.`
                        : ''
                }
                confirmLabel="Eliminar"
                loading={deleting}
                onConfirm={handleConfirmDelete}
                onCancel={() => !deleting && setConfirmDelete(null)}
            />
        </PageShell>
    );
}
