import RoleBadge from '@/components/role-badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { FilterBar } from '@/components/ui/filter-bar';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import type { Branch, CompanyMember, CompanyRole } from '@/types';
import { Eye, KeyRound, MapPin, ShieldOff, Trash2, UserCheck, Users as UsersIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

const PAGE_SIZE = 20;

interface UsersTableProps {
    members: CompanyMember[];
    roles: CompanyRole[];
    canManage: boolean;
    currentUserId?: string;
    onRoleChange: (userId: string, roleId: string) => Promise<void>;
    onRemoveUser: (userId: string) => Promise<void>;
    onBulkRoleChange?: (userIds: string[], roleId: string) => Promise<void>;
    onEditPermissions?: (member: CompanyMember) => void;
    onToggleStatus?: (userId: string, newStatus: 'active' | 'inactive') => Promise<void>;
    onViewDetail?: (member: CompanyMember) => void;
    branches?: Branch[];
    onBulkBranchAssign?: (userIds: string[], branchId: string, action: 'attach' | 'detach') => Promise<void>;
}

function userStatusBadge(status?: string) {
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

export default function UsersTable({
    members,
    roles,
    canManage,
    currentUserId,
    onRoleChange,
    onRemoveUser,
    onBulkRoleChange,
    onEditPermissions,
    onToggleStatus,
    onViewDetail,
    branches = [],
    onBulkBranchAssign,
}: UsersTableProps) {
    const [search, setSearch] = useState('');
    const [roleFilter, setRoleFilter] = useState('');
    const [loadingId, setLoadingId] = useState<string | null>(null);
    const [removingId, setRemovingId] = useState<string | null>(null);
    const [togglingStatusId, setTogglingStatusId] = useState<string | null>(null);
    const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
    const [bulkRoleId, setBulkRoleId] = useState<string>('');
    const [bulkApplying, setBulkApplying] = useState(false);
    const [bulkBranchId, setBulkBranchId] = useState<string>('');
    const [bulkBranchApplying, setBulkBranchApplying] = useState<'attach' | 'detach' | null>(null);
    const [page, setPage] = useState(1);
    const [confirmRemove, setConfirmRemove] = useState<CompanyMember | null>(null);
    const [confirmToggle, setConfirmToggle] = useState<CompanyMember | null>(null);

    useEffect(() => {
        setPage(1);
        setSelectedIds(new Set());
    }, [search, roleFilter, members]);

    const filtered = members.filter((m) => {
        const matchesSearch =
            !search || m.user.name.toLowerCase().includes(search.toLowerCase()) || m.user.email.toLowerCase().includes(search.toLowerCase());
        const matchesRole = !roleFilter || String(m.role?.id) === roleFilter;
        return matchesSearch && matchesRole;
    });

    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    const safePage = Math.min(page, totalPages);
    const paginated = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

    const selectableIds = paginated.filter((m) => !m.role?.is_system).map((m) => m.user_id);
    const allSelected = selectableIds.length > 0 && selectableIds.every((id) => selectedIds.has(id));
    const someSelected = selectableIds.some((id) => selectedIds.has(id));

    const toggleSelectAll = () => {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (allSelected) {
                selectableIds.forEach((id) => next.delete(id));
            } else {
                selectableIds.forEach((id) => next.add(id));
            }
            return next;
        });
    };

    const toggleSelect = (userId: string) => {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(userId)) {
                next.delete(userId);
            } else {
                next.add(userId);
            }
            return next;
        });
    };

    const handleBulkApply = async () => {
        if (!onBulkRoleChange || !bulkRoleId || selectedIds.size === 0) return;
        setBulkApplying(true);
        try {
            await onBulkRoleChange(Array.from(selectedIds), bulkRoleId);
            setSelectedIds(new Set());
            setBulkRoleId('');
        } finally {
            setBulkApplying(false);
        }
    };

    const handleBulkBranchApply = async (action: 'attach' | 'detach') => {
        if (!onBulkBranchAssign || !bulkBranchId || selectedIds.size === 0) return;
        setBulkBranchApplying(action);
        try {
            await onBulkBranchAssign(Array.from(selectedIds), bulkBranchId, action);
            setSelectedIds(new Set());
            setBulkBranchId('');
        } finally {
            setBulkBranchApplying(null);
        }
    };

    const handleRoleChange = async (userId: string, newRoleId: string) => {
        setLoadingId(userId);
        try {
            await onRoleChange(userId, newRoleId);
        } finally {
            setLoadingId(null);
        }
    };

    const confirmToggleStatus = async () => {
        if (!confirmToggle || !onToggleStatus) return;
        const member = confirmToggle;
        const newStatus = member.status === 'active' ? 'inactive' : 'active';
        setConfirmToggle(null);
        setTogglingStatusId(member.user_id);
        try {
            await onToggleStatus(member.user_id, newStatus);
        } finally {
            setTogglingStatusId(null);
        }
    };

    const confirmRemoveUser = async () => {
        if (!confirmRemove) return;
        const member = confirmRemove;
        setConfirmRemove(null);
        setRemovingId(member.user_id);
        try {
            await onRemoveUser(member.user_id);
        } finally {
            setRemovingId(null);
        }
    };

    const showBulkBar = canManage && !!onBulkRoleChange;
    // La columna de acciones existe si se puede gestionar o al menos ver detalle.
    const showActions = canManage || !!onViewDetail;
    // +1 col por "Sedes". Estructura base: Usuario, Email, Rol, Sedes, Estado, Acceso (= 6).
    // Si hay bulk → +1 (checkbox). Si hay acciones → +1.
    const colSpanEmpty = 6 + (showBulkBar ? 1 : 0) + (showActions ? 1 : 0);

    return (
        <>
            <Card className="w-full rounded-2xl shadow-sm">
                <CardContent className="p-0">
                    {/* Bulk action bar */}
                    {showBulkBar && selectedIds.size > 0 && (
                        <div className="bg-primary/5 border-b px-4 py-3">
                            <div className="flex flex-wrap items-center gap-3">
                                <span className="text-sm font-medium">{selectedIds.size} seleccionado(s)</span>
                                <Select value={bulkRoleId} onValueChange={setBulkRoleId}>
                                    <SelectTrigger className="h-8 w-44 text-xs">
                                        <SelectValue placeholder="Asignar rol..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roles.map((r) => (
                                            <SelectItem key={r.id} value={String(r.id)}>
                                                {r.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button size="sm" variant="accent" disabled={!bulkRoleId || bulkApplying} onClick={handleBulkApply}>
                                    {bulkApplying ? 'Aplicando...' : 'Aplicar rol'}
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setSelectedIds(new Set())}>
                                    Cancelar
                                </Button>
                            </div>

                            {/* Multi-sede: bulk asignar/quitar acceso a sede */}
                            {onBulkBranchAssign && branches.length > 0 && (
                                <div className="mt-2 flex flex-wrap items-center gap-3">
                                    <MapPin className="text-muted-foreground size-4" />
                                    <Select value={bulkBranchId} onValueChange={setBulkBranchId}>
                                        <SelectTrigger className="h-8 w-44 text-xs">
                                            <SelectValue placeholder="Sede..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {branches.map((b) => (
                                                <SelectItem key={b.id} value={b.id}>
                                                    {b.name}
                                                    {b.is_default ? ' (principal)' : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        size="sm"
                                        variant="accent"
                                        disabled={!bulkBranchId || bulkBranchApplying !== null}
                                        onClick={() => handleBulkBranchApply('attach')}
                                    >
                                        {bulkBranchApplying === 'attach' ? 'Asignando...' : 'Asignar a sede'}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={!bulkBranchId || bulkBranchApplying !== null}
                                        onClick={() => handleBulkBranchApply('detach')}
                                    >
                                        {bulkBranchApplying === 'detach' ? 'Quitando...' : 'Quitar acceso'}
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Filters */}
                    <div className="p-4 pb-2">
                        <FilterBar
                            searchValue={search}
                            onSearchChange={setSearch}
                            searchPlaceholder="Buscar por nombre o email"
                            searchClassName="max-w-xs"
                        >
                            <Select value={roleFilter || 'all'} onValueChange={(val) => setRoleFilter(val === 'all' ? '' : val)}>
                                <SelectTrigger className="w-full sm:w-48">
                                    <SelectValue placeholder="Todos los roles" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos los roles</SelectItem>
                                    {roles.map((role) => (
                                        <SelectItem key={role.id} value={String(role.id)}>
                                            {role.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FilterBar>
                    </div>

                    <Separator />

                    {/* Mobile card list */}
                    <div className="divide-border divide-y md:hidden">
                        {paginated.length === 0 ? (
                            <EmptyState />
                        ) : (
                            paginated.map((member) => {
                                const user = member.user;
                                const role = member.role;
                                const isLoading = loadingId === user.id;
                                const isRemoving = removingId === member.user_id;

                                return (
                                    <div key={member.id} className="space-y-3 p-4">
                                        <div className="flex items-center gap-3">
                                            <Avatar className="h-10 w-10 shrink-0">
                                                {user.avatar_url ? (
                                                    <AvatarImage src={user.avatar_url} alt={user.name} />
                                                ) : (
                                                    <AvatarFallback>{user.name?.[0]?.toUpperCase()}</AvatarFallback>
                                                )}
                                            </Avatar>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold">{user.name}</p>
                                                <p className="text-muted-foreground truncate text-xs">{user.email}</p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-1">{userStatusBadge(user.status)}</div>
                                        </div>

                                        <div className="flex max-w-full min-w-0 flex-wrap items-center gap-2">
                                            {role ? (
                                                <RoleBadge name={role.name} color={role.color} isSystem={role.is_system} />
                                            ) : (
                                                <span className="text-muted-foreground text-sm">Sin rol</span>
                                            )}
                                            {member.status === 'active' ? (
                                                <Badge variant="safe" className="shrink-0">
                                                    Acceso activo
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline" className="text-muted-foreground shrink-0">
                                                    No vinculado
                                                </Badge>
                                            )}
                                        </div>

                                        {onViewDetail && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => onViewDetail(member)}
                                                className="w-full"
                                            >
                                                <Eye className="mr-1.5 h-4 w-4" /> Ver detalle
                                            </Button>
                                        )}

                                        {canManage && !role?.is_system && (
                                            <div className="flex gap-2">
                                                <Select
                                                    value={String(role?.id ?? '')}
                                                    onValueChange={(val) => handleRoleChange(user.id, val)}
                                                    disabled={isLoading}
                                                >
                                                    <SelectTrigger className="h-9 flex-1 text-xs">
                                                        <SelectValue placeholder="Asignar rol..." />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {roles.map((r) => (
                                                            <SelectItem key={r.id} value={String(r.id)}>
                                                                {r.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>

                                                {onToggleStatus && user.id !== currentUserId && (
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        onClick={() => setConfirmToggle(member)}
                                                        disabled={togglingStatusId === member.user_id}
                                                        className={cn(
                                                            'min-h-[44px] min-w-[44px]',
                                                            member.status === 'active'
                                                                ? 'text-[color:var(--color-status-warning)] hover:text-[color:var(--color-status-warning)]'
                                                                : 'text-[color:var(--color-status-safe)] hover:text-[color:var(--color-status-safe)]',
                                                        )}
                                                        title={member.status === 'active' ? 'Desactivar acceso' : 'Reactivar acceso'}
                                                    >
                                                        {member.status === 'active' ? (
                                                            <ShieldOff className="h-4 w-4" />
                                                        ) : (
                                                            <UserCheck className="h-4 w-4" />
                                                        )}
                                                    </Button>
                                                )}
                                                {onEditPermissions && (
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        onClick={() => onEditPermissions(member)}
                                                        className="text-primary hover:text-primary min-h-[44px] min-w-[44px]"
                                                        title="Editar permisos"
                                                    >
                                                        <KeyRound className="h-4 w-4" />
                                                    </Button>
                                                )}
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    onClick={() => setConfirmRemove(member)}
                                                    disabled={isRemoving}
                                                    className="text-muted-foreground hover:text-destructive min-h-[44px] min-w-[44px]"
                                                    title="Desvincular usuario"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                );
                            })
                        )}
                    </div>

                    {/* Desktop table */}
                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-foreground text-xs uppercase">
                                <tr>
                                    {showBulkBar && (
                                        <th className="w-10 px-4 py-3">
                                            <Checkbox
                                                checked={allSelected ? true : someSelected ? 'indeterminate' : false}
                                                onCheckedChange={toggleSelectAll}
                                                aria-label="Seleccionar todos"
                                                disabled={selectableIds.length === 0}
                                            />
                                        </th>
                                    )}
                                    <th className="px-4 py-3 text-left font-semibold">Usuario</th>
                                    <th className="px-4 py-3 text-left font-semibold">Email</th>
                                    <th className="px-4 py-3 text-left font-semibold">Rol</th>
                                    <th className="px-4 py-3 text-left font-semibold">Sedes</th>
                                    <th className="px-4 py-3 text-left font-semibold">Estado</th>
                                    <th className="px-4 py-3 text-left font-semibold">Acceso</th>
                                    {showActions && <th className="px-4 py-3 text-center font-semibold">Acciones</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {paginated.length === 0 ? (
                                    <tr>
                                        <td colSpan={colSpanEmpty} className="p-0">
                                            <EmptyState />
                                        </td>
                                    </tr>
                                ) : (
                                    paginated.map((member) => {
                                        const user = member.user;
                                        const role = member.role;
                                        const isLoading = loadingId === user.id;
                                        const isRemoving = removingId === member.user_id;
                                        const isSelectable = !role?.is_system;

                                        return (
                                            <tr key={member.id} className="hover:bg-muted/40 border-border border-t transition-colors">
                                                {showBulkBar && (
                                                    <td className="px-4 py-3">
                                                        <Checkbox
                                                            checked={selectedIds.has(member.user_id)}
                                                            disabled={!isSelectable}
                                                            onCheckedChange={() => isSelectable && toggleSelect(member.user_id)}
                                                            aria-label={`Seleccionar ${user.name}`}
                                                        />
                                                    </td>
                                                )}
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-2">
                                                        <Avatar className="h-8 w-8">
                                                            {user.avatar_url ? (
                                                                <AvatarImage src={user.avatar_url} alt={user.name} />
                                                            ) : (
                                                                <AvatarFallback>{user.name?.[0]?.toUpperCase()}</AvatarFallback>
                                                            )}
                                                        </Avatar>
                                                        <span className="text-sm font-medium">{user.name}</span>
                                                    </div>
                                                </td>
                                                <td className="text-muted-foreground px-4 py-3 text-sm">{user.email}</td>
                                                <td className="px-4 py-3">
                                                    {canManage && !role?.is_system ? (
                                                        <div className="flex items-center gap-2">
                                                            {role && <RoleBadge name={role.name} color={role.color} isSystem={role.is_system} />}
                                                            <Select
                                                                value={String(role?.id ?? '')}
                                                                onValueChange={(val) => handleRoleChange(user.id, val)}
                                                                disabled={isLoading}
                                                            >
                                                                <SelectTrigger className="h-8 w-36 text-xs">
                                                                    <SelectValue />
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
                                                    ) : role ? (
                                                        <RoleBadge name={role.name} color={role.color} isSystem={role.is_system} />
                                                    ) : (
                                                        <span className="text-sm">—</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex flex-wrap items-center gap-1">
                                                        {member.branch_ids && member.branch_ids.length > 0 ? (
                                                            member.branch_ids.map((bid) => {
                                                                const b = branches.find((x) => x.id === bid);
                                                                if (!b) return null;
                                                                return (
                                                                    <Badge
                                                                        key={bid}
                                                                        variant="outline"
                                                                        className="border-primary/30 bg-primary/10 text-primary gap-1"
                                                                    >
                                                                        <MapPin className="h-3 w-3" />
                                                                        {b.name}
                                                                    </Badge>
                                                                );
                                                            })
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs">—</span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">{userStatusBadge(user.status)}</td>
                                                <td className="px-4 py-3">
                                                    {member.status === 'active' ? (
                                                        <Badge variant="safe">Activo</Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="text-muted-foreground">
                                                            No vinculado
                                                        </Badge>
                                                    )}
                                                </td>
                                                {showActions && (
                                                    <td className="px-4 py-3 text-center">
                                                        <div className="flex items-center justify-center gap-1">
                                                            {onViewDetail && (
                                                                <Button
                                                                    size="icon"
                                                                    variant="ghost"
                                                                    onClick={() => onViewDetail(member)}
                                                                    title="Ver detalle"
                                                                    className="text-muted-foreground hover:text-foreground"
                                                                >
                                                                    <Eye className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                            {canManage && !role?.is_system && (
                                                                <>
                                                                    {onToggleStatus && user.id !== currentUserId && (
                                                                        <Button
                                                                            size="icon"
                                                                            variant="ghost"
                                                                            onClick={() => setConfirmToggle(member)}
                                                                            disabled={togglingStatusId === member.user_id}
                                                                            title={
                                                                                member.status === 'active' ? 'Desactivar acceso' : 'Reactivar acceso'
                                                                            }
                                                                            className={
                                                                                member.status === 'active'
                                                                                    ? 'text-[color:var(--color-status-warning)] hover:text-[color:var(--color-status-warning)]'
                                                                                    : 'text-[color:var(--color-status-safe)] hover:text-[color:var(--color-status-safe)]'
                                                                            }
                                                                        >
                                                                            {member.status === 'active' ? (
                                                                                <ShieldOff className="h-4 w-4" />
                                                                            ) : (
                                                                                <UserCheck className="h-4 w-4" />
                                                                            )}
                                                                        </Button>
                                                                    )}
                                                                    {onEditPermissions && (
                                                                        <Button
                                                                            size="icon"
                                                                            variant="ghost"
                                                                            onClick={() => onEditPermissions(member)}
                                                                            title="Editar permisos"
                                                                            className="text-primary hover:text-primary"
                                                                        >
                                                                            <KeyRound className="h-4 w-4" />
                                                                        </Button>
                                                                    )}
                                                                    <Button
                                                                        size="icon"
                                                                        variant="ghost"
                                                                        onClick={() => setConfirmRemove(member)}
                                                                        disabled={isRemoving}
                                                                        title="Desvincular usuario"
                                                                        className="text-muted-foreground hover:text-destructive"
                                                                    >
                                                                        <Trash2 className="h-4 w-4" />
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </div>
                                                    </td>
                                                )}
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {totalPages > 1 && (
                        <div className="border-border flex items-center justify-between border-t px-4 py-3">
                            <span className="text-muted-foreground text-xs">
                                {filtered.length} usuario(s) · Página {safePage} de {totalPages}
                            </span>
                            <div className="flex gap-1">
                                <Button size="sm" variant="outline" disabled={safePage <= 1} onClick={() => setPage((p) => p - 1)}>
                                    Anterior
                                </Button>
                                <Button size="sm" variant="outline" disabled={safePage >= totalPages} onClick={() => setPage((p) => p + 1)}>
                                    Siguiente
                                </Button>
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>

            <ConfirmDialog
                open={confirmRemove !== null}
                title="Desvincular usuario"
                message={confirmRemove ? `¿Desvincular a ${confirmRemove.user.name} de la empresa? Perderá acceso inmediatamente.` : ''}
                confirmLabel="Desvincular"
                loading={removingId !== null}
                onConfirm={() => void confirmRemoveUser()}
                onCancel={() => setConfirmRemove(null)}
            />

            <ConfirmDialog
                open={confirmToggle !== null}
                title={confirmToggle?.status === 'active' ? 'Desactivar acceso' : 'Reactivar acceso'}
                message={
                    confirmToggle
                        ? confirmToggle.status === 'active'
                            ? `¿Desactivar el acceso de ${confirmToggle.user.name}? Podrá reactivarse después.`
                            : `¿Reactivar el acceso de ${confirmToggle.user.name}?`
                        : ''
                }
                confirmLabel={confirmToggle?.status === 'active' ? 'Desactivar' : 'Reactivar'}
                loading={togglingStatusId !== null}
                onConfirm={() => void confirmToggleStatus()}
                onCancel={() => setConfirmToggle(null)}
            />
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-3 py-12 text-center">
            <div className="bg-muted text-muted-foreground rounded-full p-3">
                <UsersIcon className="h-5 w-5" />
            </div>
            <p className="text-muted-foreground text-sm">Sin usuarios que coincidan con los filtros.</p>
        </div>
    );
}
