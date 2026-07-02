import InviteUserModal from '@/components/invite-user-modal';
import { PageShell } from '@/components/page-shell';
import { PendingInvitationsCard } from '@/components/pending-invitations-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { UsersTableSkeleton } from '@/components/ui/users-table-skeleton';
import UserDetailModal from '@/components/user-detail-modal';
import UserPermissionsEditor from '@/components/user-permissions-editor';
import UsersTable from '@/components/users-table';
import { useToast } from '@/components/ui/toast';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import type { Branch, CompanyMember, CompanyRole, CompanyRolePermission, Feature, User } from '@/types';

import { AlertCircle, Lock, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';


export default function Users() {
    const activeToken = useToken();
    const { showToast } = useToast();
    const { activeCompany, auth } = useSharedData();
    const currentUser = auth?.user as User | undefined;

    const [members, setMembers] = useState<CompanyMember[]>([]);
    const [roles, setRoles] = useState<CompanyRole[]>([]);
    const [features, setFeatures] = useState<Feature[]>([]);
    const [branches, setBranches] = useState<Branch[]>([]);
    const [canManage, setCanManage] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [editingPermissionsMember, setEditingPermissionsMember] = useState<CompanyMember | null>(null);
    const [detailMember, setDetailMember] = useState<CompanyMember | null>(null);
    const [actorPermissions, setActorPermissions] = useState<CompanyRolePermission[]>([]);

    const isMounted = useRef(true);

    const fetchData = async () => {
        try {
            const [usersRes, rolesRes, featuresRes] = await Promise.all([
                apiFetch('/api/v1/users'),
                apiFetch('/api/v1/roles'),
                apiFetch('/api/v1/features'),
            ]);

            if (!usersRes.ok || !rolesRes.ok || !featuresRes.ok) {
                setError('Error al cargar datos. Intenta recargar la página.');
                return;
            }

            const [usersData, rolesData, featuresData] = await Promise.all([usersRes.json(), rolesRes.json(), featuresRes.json()]);

            if (!isMounted.current) return;

            setMembers(usersData.data ?? []);
            setCanManage(usersData.can_manage ?? false);
            setRoles(rolesData.data ?? []);
            setFeatures(featuresData.data ?? []);
            setActorPermissions(usersData.actor_permissions ?? []);
            setBranches(usersData.branches ?? []);
        } catch {
            if (!isMounted.current) return;
            setError('Error de conexión. Intenta recargar.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    };

    useEffect(() => {
        isMounted.current = true;

        const timer = setTimeout(() => {
            fetchData();
        }, 0);

        return () => {
            isMounted.current = false;
            clearTimeout(timer);
        };
    }, [activeToken]);

    useEffect(() => {
        setMembers([]);
        setRoles([]);
        setLoading(true);
        setError(null);
    }, [activeCompany?.nit]);

    const reportError = async (res: Response, fallback: string) => {
        let message = fallback;
        try {
            const data = await res.json();
            if (typeof data?.message === 'string' && data.message) {
                message = data.message;
            }
        } catch {
            // respuesta sin cuerpo JSON — usar el fallback.
        }
        showToast('error', message);
    };

    const handleRoleChange = async (userId: string, roleId: string) => {
        try {
            const res = await apiFetch(`/api/v1/users/${userId}/role`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ company_role_id: roleId }),
            });

            if (res.ok) {
                const updated = await res.json();
                setMembers((prev) => prev.map((m) => (m.user_id === userId ? { ...m, ...updated.data } : m)));
            } else {
                await reportError(res, 'No se pudo cambiar el rol.');
            }
        } catch {
            showToast('error', 'Error de conexión al cambiar el rol.');
        }
    };

    const handleRemoveUser = async (userId: string) => {
        try {
            const res = await apiFetch(`/api/v1/users/${userId}`, { method: 'DELETE' });
            if (res.ok) {
                setMembers((prev) => prev.filter((m) => m.user_id !== userId));
            } else {
                await reportError(res, 'No se pudo remover al usuario.');
            }
        } catch {
            showToast('error', 'Error de conexión al remover al usuario.');
        }
    };

    const handleToggleStatus = async (userId: string, newStatus: 'active' | 'inactive') => {
        try {
            const res = await apiFetch(`/api/v1/users/${userId}/status`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status: newStatus }),
            });

            if (res.ok) {
                setMembers((prev) => prev.map((m) => (m.user_id === userId ? { ...m, status: newStatus } : m)));
            } else {
                await reportError(res, 'No se pudo cambiar el estado del usuario.');
            }
        } catch {
            showToast('error', 'Error de conexión al cambiar el estado.');
        }
    };

    const handleBulkBranchAssign = async (userIds: string[], branchId: string, action: 'attach' | 'detach') => {
        try {
            const res = await apiFetch('/api/v1/company/branches/bulk-assign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ branch_id: branchId, user_ids: userIds, action }),
            });
            if (!res.ok) {
                await reportError(res, 'No se pudo actualizar la asignación de sede.');
            }
        } catch {
            showToast('error', 'Error de conexión al asignar la sede.');
        }
        await fetchData();
    };

    /**
     * Asigna/quita a UN usuario el acceso a una sede (branch_users) desde el
     * modal de detalle. Reusa el endpoint bulk-assign con un solo user_id.
     * Optimista: refleja el cambio al instante y revierte con refetch si falla.
     */
    const handleBranchToggle = async (userId: string, branchId: string, action: 'attach' | 'detach') => {
        setMembers((prev) =>
            prev.map((m) => {
                if (m.user_id !== userId) return m;
                const current = m.branch_ids ?? [];
                const next = action === 'attach' ? Array.from(new Set([...current, branchId])) : current.filter((id) => id !== branchId);
                return { ...m, branch_ids: next };
            }),
        );
        try {
            const res = await apiFetch('/api/v1/company/branches/bulk-assign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ branch_id: branchId, user_ids: [userId], action }),
            });
            if (!res.ok) {
                await reportError(res, 'No se pudo actualizar la sede.');
                await fetchData();
            }
        } catch {
            showToast('error', 'Error de conexión al asignar la sede.');
            await fetchData();
        }
    };

    const handleBulkRoleChange = async (userIds: string[], roleId: string) => {
        const results = await Promise.allSettled(
            userIds.map((userId) =>
                apiFetch(`/api/v1/users/${userId}/role`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ company_role_id: roleId }),
                }),
            ),
        );
        const failed = results.filter((r) => r.status === 'rejected' || (r.status === 'fulfilled' && !r.value.ok)).length;
        if (failed > 0) {
            showToast('error', `${failed} de ${userIds.length} usuarios no se actualizaron.`);
        }
        await fetchData();
    };

    const handleInvited = () => {
        fetchData();
    };

    const handleEditPermissions = (member: CompanyMember) => {
        setEditingPermissionsMember(member);
    };

    const handlePermissionsSaved = () => {
        setEditingPermissionsMember(null);
        fetchData();
    };

    const headerActions = (
        <>
            <Button variant="outline" size="sm" onClick={() => void fetchData()} disabled={loading} title="Actualizar">
                <RefreshCw className={`mr-1.5 h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                Actualizar
            </Button>
            {canManage && <InviteUserModal roles={roles} onInvited={handleInvited} />}
        </>
    );

    if (loading) {
        return (
            <PageShell title="Usuarios">
                <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                    <PageHeader eyebrow="USUARIOS" title="Usuarios" description="Invita, asigna roles y gestiona accesos del equipo." />
                    <UsersTableSkeleton />
                </div>
            </PageShell>
        );
    }

    return (
        <PageShell title="Usuarios">
            <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="USUARIOS"
                    title="Usuarios"
                    description="Invita, asigna roles y gestiona accesos del equipo."
                    actions={headerActions}
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {!canManage && !error && (
                    <Alert variant="warning">
                        <Lock className="h-4 w-4" />
                        <AlertDescription>
                            Solo tienes permisos de lectura. Contacta al propietario de la empresa para gestionar usuarios.
                        </AlertDescription>
                    </Alert>
                )}

                <UsersTable
                    members={members}
                    roles={roles}
                    canManage={canManage}
                    currentUserId={currentUser?.id}
                    onRoleChange={handleRoleChange}
                    onRemoveUser={handleRemoveUser}
                    onBulkRoleChange={handleBulkRoleChange}
                    onEditPermissions={handleEditPermissions}
                    onToggleStatus={canManage ? handleToggleStatus : undefined}
                    onViewDetail={setDetailMember}
                    branches={branches}
                    onBulkBranchAssign={canManage ? handleBulkBranchAssign : undefined}
                />

                {/*
                 * Lista de invitaciones pendientes. Cuando un miembro acepta
                 * una invitación, el siguiente fetchData() de la tabla refleja
                 * la nueva membership; por eso pasamos fetchData como
                 * onChanged.
                 */}
                <PendingInvitationsCard canManage={canManage} onChanged={fetchData} />

                {editingPermissionsMember && (
                    <UserPermissionsEditor
                        member={editingPermissionsMember}
                        features={features}
                        actorPermissions={actorPermissions}
                        onClose={() => setEditingPermissionsMember(null)}
                        onSaved={handlePermissionsSaved}
                    />
                )}

                <UserDetailModal
                    member={detailMember ? (members.find((m) => m.user_id === detailMember.user_id) ?? detailMember) : null}
                    open={detailMember !== null}
                    onClose={() => setDetailMember(null)}
                    roles={roles}
                    branches={branches}
                    canManage={canManage}
                    currentUserId={currentUser?.id}
                    onRoleChange={handleRoleChange}
                    onToggleStatus={canManage ? handleToggleStatus : undefined}
                    onEditPermissions={handleEditPermissions}
                    onRemoveUser={handleRemoveUser}
                    onBranchToggle={canManage ? handleBranchToggle : undefined}
                />
            </div>
        </PageShell>
    );
}
