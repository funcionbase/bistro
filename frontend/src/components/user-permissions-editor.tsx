import PermissionsMatrix from '@/components/permissions-matrix';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { apiFetch } from '@/lib/api';
import type { CompanyMember, CompanyRolePermission, Feature } from '@/types';
import { AlertCircle, LoaderCircle } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface UserPermissionsEditorProps {
    member: CompanyMember;
    features: Feature[];
    actorPermissions: CompanyRolePermission[];
    onClose: () => void;
    onSaved: () => void;
}

export default function UserPermissionsEditor({ member, features, actorPermissions, onClose, onSaved }: UserPermissionsEditorProps) {
    const [permissions, setPermissions] = useState<CompanyRolePermission[]>(() =>
        features.map((f) => {
            const found = member.role?.permissions?.find((p) => p.feature_id === f.id);
            return {
                id: found?.id ?? '',
                company_role_id: member.role?.id ?? '',
                feature_id: f.id,
                can_create: found?.can_create ?? false,
                can_read: found?.can_read ?? false,
                can_update: found?.can_update ?? false,
                can_delete: found?.can_delete ?? false,
            };
        }),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const isPermissionDisabled = (featureId: string, action: string): boolean => {
        return !actorPermissions.some((p) => p.feature_id === featureId && p[action as keyof CompanyRolePermission] === true);
    };

    const handleChangePerm = (featureId: string, action: string, value: boolean) => {
        if (isPermissionDisabled(featureId, action) && value) {
            return;
        }
        setPermissions((prev) => prev.map((p) => (p.feature_id === featureId ? { ...p, [action]: value } : p)));
    };

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setErrors({});
        setProcessing(true);

        const permissionPayload = permissions.map((p) => ({
            feature_id: p.feature_id,
            can_create: p.can_create,
            can_read: p.can_read,
            can_update: p.can_update,
            can_delete: p.can_delete,
        }));

        try {
            const res = await apiFetch(`/api/v1/users/${member.user_id}/permissions`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ permissions: permissionPayload }),
            });

            const data = await res.json();

            if (!res.ok) {
                if (res.status === 422 && data.errors) {
                    const mapped: Record<string, string> = {};
                    for (const [field, messages] of Object.entries(data.errors as Record<string, string[]>)) {
                        mapped[field] = messages[0];
                    }
                    setErrors(mapped);
                } else {
                    setErrors({ general: data.message ?? 'Ocurrió un error.' });
                }
                return;
            }

            onSaved();
        } catch {
            setErrors({ general: 'Error de conexión. Intenta de nuevo.' });
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open onOpenChange={(o) => !o && !processing && onClose()}>
            <DialogContent className="flex max-h-[90vh] max-w-2xl flex-col overflow-hidden">
                <DialogHeader>
                    <DialogTitle>Editar permisos del rol{member.role?.name ? ` ${member.role.name}` : ''}</DialogTitle>
                    <DialogDescription>
                        Estos permisos pertenecen al rol{member.role?.name ? ` "${member.role.name}"` : ''}, no al usuario.
                        Los cambios aplican a todos los usuarios con este rol.
                    </DialogDescription>
                </DialogHeader>

                <form noValidate onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col gap-4">
                    <div className="flex-1 min-h-0 space-y-4 overflow-y-auto">
                        <Alert variant="warning">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>
                                Editás el rol{member.role?.name ? ` "${member.role.name}"` : ''}, que es compartido. Cualquier cambio
                                afecta a <strong>todos</strong> los usuarios con este rol, no solo a {member.user.name}.
                            </AlertDescription>
                        </Alert>

                        {errors.general && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{errors.general}</AlertDescription>
                            </Alert>
                        )}

                        <PermissionsMatrix
                            permissions={permissions}
                            features={features}
                            onChangePerm={handleChangePerm}
                            disabledCheck={isPermissionDisabled}
                        />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={processing}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : 'Guardar permisos'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
