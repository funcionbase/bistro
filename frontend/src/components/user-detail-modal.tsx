import RoleBadge from '@/components/role-badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DetailRow } from '@/components/ui/detail-row';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import type { Branch, CompanyMember, CompanyRole } from '@/types';
import { KeyRound, MapPin, ShieldOff, Trash2, UserCheck } from 'lucide-react';
import { useState } from 'react';

interface UserDetailModalProps {
    member: CompanyMember | null;
    open: boolean;
    onClose: () => void;
    roles: CompanyRole[];
    branches: Branch[];
    canManage: boolean;
    currentUserId?: string;
    onRoleChange: (userId: string, roleId: string) => Promise<void>;
    onToggleStatus?: (userId: string, newStatus: 'active' | 'inactive') => Promise<void>;
    onEditPermissions?: (member: CompanyMember) => void;
    onRemoveUser: (userId: string) => Promise<void>;
}

function statusBadge(status?: string) {
    switch (status) {
        case 'active':
            return <Badge variant="safe">Activo</Badge>;
        case 'pending_enrollment':
            return <Badge variant="warning">Pendiente</Badge>;
        case 'inactive':
            return (
                <Badge variant="outline" className="text-muted-foreground">
                    Inactivo
                </Badge>
            );
        default:
            return (
                <Badge variant="outline" className="text-muted-foreground">
                    {status ?? '—'}
                </Badge>
            );
    }
}

/**
 * Detalle de un usuario de la empresa. Muestra perfil, rol, sedes y estado, y
 * — para quien puede gestionar (owner/admin) y sobre usuarios distintos a sí
 * mismo — permite cambiar el rol, activar/desactivar el acceso, editar permisos
 * (roles no-sistema) y desvincular.
 *
 * A diferencia de la tabla, este modal SÍ expone las acciones cuando el target
 * tiene un rol de sistema (owner/admin): el backend conserva los guardas
 * (último owner inviolable, no auto-edición), pero el dueño necesita poder
 * gestionar a otros admins/owners desde la UI.
 */
export default function UserDetailModal({
    member,
    open,
    onClose,
    roles,
    branches,
    canManage,
    currentUserId,
    onRoleChange,
    onToggleStatus,
    onEditPermissions,
    onRemoveUser,
}: UserDetailModalProps) {
    const [savingRole, setSavingRole] = useState(false);
    const [busy, setBusy] = useState(false);
    const [confirmRemove, setConfirmRemove] = useState(false);
    const [confirmToggle, setConfirmToggle] = useState(false);

    if (!member) return null;

    const user = member.user;
    const role = member.role;
    const isSelf = !!currentUserId && String(member.user_id) === String(currentUserId);
    // Quien tiene RBAC para gestionar usuarios puede editar a otros Y a sí mismo
    // (rol, permisos). Las acciones auto-destructivas — desactivarse o
    // desvincularse a sí mismo — se ocultan abajo con `!isSelf`; el backend
    // conserva además los guardas (último owner inviolable, no auto-baja).
    const canAct = canManage;
    const memberBranches = (member.branch_ids ?? []).map((bid) => branches.find((b) => b.id === bid)).filter(Boolean) as Branch[];

    const handleRole = async (roleId: string) => {
        setSavingRole(true);
        try {
            await onRoleChange(member.user_id, roleId);
        } finally {
            setSavingRole(false);
        }
    };

    const doToggle = async () => {
        if (!onToggleStatus) return;
        setConfirmToggle(false);
        setBusy(true);
        try {
            await onToggleStatus(member.user_id, member.status === 'active' ? 'inactive' : 'active');
        } finally {
            setBusy(false);
        }
    };

    const doRemove = async () => {
        setConfirmRemove(false);
        setBusy(true);
        try {
            await onRemoveUser(member.user_id);
            onClose();
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Detalle del usuario</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="flex items-center gap-3">
                            <Avatar className="h-12 w-12 shrink-0">
                                {user.avatar_url ? <AvatarImage src={user.avatar_url} alt={user.name} /> : <AvatarFallback>{user.name?.[0]?.toUpperCase()}</AvatarFallback>}
                            </Avatar>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-base font-semibold">{user.name}</p>
                                <p className="text-muted-foreground truncate text-sm">{user.email}</p>
                            </div>
                            {statusBadge(user.status)}
                        </div>

                        <Separator />

                        <div className="space-y-2 text-sm">
                            <DetailRow
                                label="Rol"
                                value={role ? <RoleBadge name={role.name} color={role.color} isSystem={role.is_system} /> : <span className="text-muted-foreground">Sin rol</span>}
                            />
                            <DetailRow
                                label="Acceso"
                                value={
                                    member.status === 'active' ? (
                                        <Badge variant="safe">Activo</Badge>
                                    ) : (
                                        <Badge variant="outline" className="text-muted-foreground">
                                            No vinculado
                                        </Badge>
                                    )
                                }
                            />
                            <DetailRow
                                label="Sedes"
                                value={
                                    memberBranches.length > 0 ? (
                                        <div className="flex flex-wrap items-center justify-end gap-1">
                                            {memberBranches.map((b) => (
                                                <Badge key={b.id} variant="outline" className="border-primary/30 bg-primary/10 text-primary gap-1">
                                                    <MapPin className="h-3 w-3" />
                                                    {b.name}
                                                </Badge>
                                            ))}
                                        </div>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )
                                }
                            />
                        </div>

                        {isSelf && (
                            <p className="text-muted-foreground rounded-lg border border-dashed px-3 py-2 text-xs">
                                Este eres tú. Aquí puedes ajustar tu rol y permisos; tu nombre y datos personales se editan en tu perfil
                                en <span className="font-medium">/me</span>.
                            </p>
                        )}

                        {canAct && (
                            <>
                                <Separator />
                                <div className="space-y-3">
                                    <div className="space-y-1.5">
                                        <Label className="text-xs">Cambiar rol</Label>
                                        <Select value={String(role?.id ?? '')} onValueChange={handleRole} disabled={savingRole || busy}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Selecciona un rol" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {roles.map((r) => (
                                                    <SelectItem key={r.id} value={String(r.id)}>
                                                        {r.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        {onToggleStatus && !isSelf && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setConfirmToggle(true)}
                                                disabled={busy}
                                                className={
                                                    member.status === 'active'
                                                        ? 'text-[color:var(--color-status-warning)] hover:text-[color:var(--color-status-warning)]'
                                                        : 'text-[color:var(--color-status-safe)] hover:text-[color:var(--color-status-safe)]'
                                                }
                                            >
                                                {member.status === 'active' ? <ShieldOff className="mr-1.5 h-4 w-4" /> : <UserCheck className="mr-1.5 h-4 w-4" />}
                                                {member.status === 'active' ? 'Desactivar acceso' : 'Reactivar acceso'}
                                            </Button>
                                        )}
                                        {onEditPermissions && !role?.is_system && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="text-primary hover:text-primary"
                                                onClick={() => {
                                                    onEditPermissions(member);
                                                    onClose();
                                                }}
                                            >
                                                <KeyRound className="mr-1.5 h-4 w-4" /> Editar permisos
                                            </Button>
                                        )}
                                        {!isSelf && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="text-muted-foreground hover:text-destructive"
                                                onClick={() => setConfirmRemove(true)}
                                                disabled={busy}
                                            >
                                                <Trash2 className="mr-1.5 h-4 w-4" /> Desvincular
                                            </Button>
                                        )}
                                    </div>
                                    {role?.is_system && (
                                        <p className="text-muted-foreground text-xs">
                                            Los permisos de roles de sistema (propietario/administrador) se gestionan en Roles, no aquí.
                                        </p>
                                    )}
                                </div>
                            </>
                        )}
                    </div>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirmToggle}
                title={member.status === 'active' ? 'Desactivar acceso' : 'Reactivar acceso'}
                message={
                    member.status === 'active'
                        ? `¿Desactivar el acceso de ${user.name}? Podrá reactivarse después.`
                        : `¿Reactivar el acceso de ${user.name}?`
                }
                confirmLabel={member.status === 'active' ? 'Desactivar' : 'Reactivar'}
                loading={busy}
                onConfirm={() => void doToggle()}
                onCancel={() => setConfirmToggle(false)}
            />

            <ConfirmDialog
                open={confirmRemove}
                title="Desvincular usuario"
                message={`¿Desvincular a ${user.name} de la empresa? Perderá acceso inmediatamente.`}
                confirmLabel="Desvincular"
                loading={busy}
                onConfirm={() => void doRemove()}
                onCancel={() => setConfirmRemove(false)}
            />
        </>
    );
}
