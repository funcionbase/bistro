import InputError from '@/components/input-error';
import PermissionsMatrix from '@/components/permissions-matrix';
import RoleBadge from '@/components/role-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { apiFetch } from '@/lib/api';
import type { CompanyRole, CompanyRolePermission, Feature } from '@/types';
import { AlertCircle, LoaderCircle, X } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

interface RoleEditorProps {
    role: CompanyRole | null;
    features: Feature[];
    existingRoles?: CompanyRole[];
    onClose: () => void;
    onSaved: (mode: 'created' | 'updated') => void;
}

const SWATCHES = ['#C0FD79', '#0052FF', '#6B7280', '#EF4444', '#F59E0B', '#10B981', '#8B5CF6'];

const NO_TEMPLATE = '__none__';

const pickRandomSwatch = () => SWATCHES[Math.floor(Math.random() * SWATCHES.length)];

const buildEmptyPermissions = (features: Feature[], role: CompanyRole | null): CompanyRolePermission[] =>
    features.map((f) => {
        const found = role?.permissions?.find((p) => p.feature_id === f.id);
        return {
            id: found?.id ?? '',
            company_role_id: role?.id ?? '',
            feature_id: f.id,
            can_create: found?.can_create ?? false,
            can_read: found?.can_read ?? false,
            can_update: found?.can_update ?? false,
            can_delete: found?.can_delete ?? false,
        };
    });

export default function RoleEditor({ role, features, existingRoles = [], onClose, onSaved }: RoleEditorProps) {
    const [name, setName] = useState(role?.name ?? '');
    const [description, setDescription] = useState(role?.description ?? '');
    const [color, setColor] = useState(role?.color ?? (role ? '' : pickRandomSwatch()));
    const [permissions, setPermissions] = useState<CompanyRolePermission[]>(() => buildEmptyPermissions(features, role));
    const [templateRoleId, setTemplateRoleId] = useState<string>(NO_TEMPLATE);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const isEditing = role !== null;

    const cloneableRoles = useMemo(() => existingRoles.filter((r) => r.id !== role?.id), [existingRoles, role?.id]);

    const duplicateName = useMemo(() => {
        const trimmed = name.trim().toLowerCase();
        if (!trimmed) return false;
        return existingRoles.some((r) => r.id !== role?.id && (r.name ?? '').trim().toLowerCase() === trimmed);
    }, [name, existingRoles, role?.id]);

    const totalPermsActive = useMemo(
        () => permissions.filter((p) => p.can_create || p.can_read || p.can_update || p.can_delete).length,
        [permissions],
    );

    const handleChangePerm = (featureId: string, action: string, value: boolean) => {
        setPermissions((prev) => prev.map((p) => (p.feature_id === featureId ? { ...p, [action]: value } : p)));
    };

    const handleBulkToggleColumn = (action: string, value: boolean) => {
        setPermissions((prev) => prev.map((p) => ({ ...p, [action]: value })));
    };

    const handleApplyTemplate = (sourceId: string) => {
        setTemplateRoleId(sourceId);
        if (!sourceId || sourceId === NO_TEMPLATE) {
            setPermissions(buildEmptyPermissions(features, role));
            return;
        }
        const source = existingRoles.find((r) => String(r.id) === sourceId);
        if (!source) return;
        setPermissions(
            features.map((f) => {
                const sourcePerm = source.permissions?.find((p) => p.feature_id === f.id);
                return {
                    id: '',
                    company_role_id: role?.id ?? '',
                    feature_id: f.id,
                    can_create: sourcePerm?.can_create ?? false,
                    can_read: sourcePerm?.can_read ?? false,
                    can_update: sourcePerm?.can_update ?? false,
                    can_delete: sourcePerm?.can_delete ?? false,
                };
            }),
        );
    };

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setErrors({});

        if (duplicateName) {
            setErrors({ name: 'Ya existe un rol con ese nombre en esta empresa.' });
            return;
        }

        setProcessing(true);

        const url = isEditing ? `/api/v1/roles/${role!.id}` : '/api/v1/roles';
        const method = isEditing ? 'PUT' : 'POST';

        try {
            const res = await apiFetch(url, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name.trim(), description, color: color || null, permissions }),
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

            onSaved(isEditing ? 'updated' : 'created');
        } catch {
            setErrors({ general: 'Error de conexión. Intenta de nuevo.' });
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open onOpenChange={(o) => !o && !processing && onClose()}>
            <DialogContent className="flex max-h-[90vh] flex-col overflow-hidden p-0 sm:max-w-2xl">
                <DialogHeader className="border-b px-6 py-4">
                    <DialogTitle>{isEditing ? 'Editar rol' : 'Crear rol'}</DialogTitle>
                    <DialogDescription>
                        {isEditing
                            ? 'Ajusta nombre, color y permisos. Los cambios aplican a todos los usuarios con este rol.'
                            : 'Crea un perfil con los permisos exactos que tu equipo necesita.'}
                    </DialogDescription>
                </DialogHeader>

                <form noValidate onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
                    <div className="flex-1 space-y-6 overflow-y-auto px-6 py-5">
                        {errors.general && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{errors.general}</AlertDescription>
                            </Alert>
                        )}

                        <fieldset className="space-y-4">
                            <legend className="text-muted-foreground text-[11px] font-semibold tracking-[0.15em] uppercase">Identidad</legend>

                            <div className="grid gap-2">
                                <Label htmlFor="role-name">Nombre del rol *</Label>
                                <Input
                                    id="role-name"
                                    required
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder="Ej: Cajero"
                                    aria-invalid={duplicateName || !!errors.name}
                                />
                                {duplicateName && !errors.name && (
                                    <p className="text-destructive text-xs">Ya existe un rol con ese nombre en esta empresa.</p>
                                )}
                                <InputError message={errors.name} className="text-xs" />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="role-desc">Descripción</Label>
                                <Input
                                    id="role-desc"
                                    value={description}
                                    onChange={(e) => setDescription(e.target.value)}
                                    placeholder="Para qué sirve este rol"
                                />
                                <InputError message={errors.description} className="text-xs" />
                            </div>

                            <div className="grid gap-2">
                                <Label>Color</Label>
                                <div className="flex flex-wrap items-center gap-2">
                                    {SWATCHES.map((swatch) => (
                                        <button
                                            key={swatch}
                                            type="button"
                                            title={swatch}
                                            onClick={() => setColor(swatch)}
                                            className="ring-offset-background focus-visible:ring-ring h-7 w-7 rounded-full border-2 transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                            style={{
                                                backgroundColor: swatch,
                                                borderColor: color === swatch ? 'var(--foreground)' : 'transparent',
                                            }}
                                            aria-label={`Color ${swatch}`}
                                        />
                                    ))}
                                    <label
                                        className="border-border ring-offset-background focus-within:ring-ring relative h-7 w-7 cursor-pointer overflow-hidden rounded-full border focus-within:ring-2 focus-within:ring-offset-2 focus-within:outline-none"
                                        title="Color personalizado"
                                    >
                                        <input
                                            type="color"
                                            value={color || '#6B7280'}
                                            onChange={(e) => setColor(e.target.value)}
                                            className="absolute inset-0 h-full w-full cursor-pointer border-0 bg-transparent p-0"
                                        />
                                    </label>
                                    {color && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setColor('')}
                                            className="text-muted-foreground hover:text-foreground h-7 px-2"
                                        >
                                            <X className="h-3.5 w-3.5" />
                                            Quitar
                                        </Button>
                                    )}
                                </div>
                                <div className="mt-1 flex items-center gap-2">
                                    <span className="text-muted-foreground text-xs">Vista previa:</span>
                                    <RoleBadge name={name.trim() || 'Rol'} color={color || null} />
                                </div>
                                <InputError message={errors.color} className="text-xs" />
                            </div>
                        </fieldset>

                        {cloneableRoles.length > 0 && (
                            <fieldset className="space-y-3">
                                <legend className="text-muted-foreground text-[11px] font-semibold tracking-[0.15em] uppercase">Plantilla</legend>
                                <div className="grid gap-2">
                                    <Label htmlFor="role-template">Clonar permisos de…</Label>
                                    <Select value={templateRoleId} onValueChange={handleApplyTemplate}>
                                        <SelectTrigger id="role-template">
                                            <SelectValue placeholder="— Sin plantilla —" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NO_TEMPLATE}>— Sin plantilla —</SelectItem>
                                            {cloneableRoles.map((r) => (
                                                <SelectItem key={r.id} value={String(r.id)}>
                                                    {r.name}
                                                    {r.is_system ? ' (sistema)' : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-muted-foreground text-xs">
                                        Copia los permisos del rol seleccionado como punto de partida. Puedes ajustarlos abajo.
                                    </p>
                                </div>
                            </fieldset>
                        )}

                        <fieldset className="space-y-3">
                            <div className="flex items-center justify-between">
                                <legend className="text-muted-foreground text-[11px] font-semibold tracking-[0.15em] uppercase">Permisos</legend>
                                <span className="text-muted-foreground text-xs tabular-nums">{totalPermsActive} activo(s)</span>
                            </div>
                            <PermissionsMatrix
                                features={features}
                                permissions={permissions}
                                onChange={handleChangePerm}
                                onBulkToggleColumn={handleBulkToggleColumn}
                            />
                        </fieldset>
                    </div>

                    <DialogFooter className="border-t px-6 py-4 sm:gap-2">
                        <Button type="button" variant="outline" onClick={onClose} disabled={processing}>
                            Cancelar
                        </Button>
                        <Button type="submit" variant="default" disabled={processing || duplicateName}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : isEditing ? 'Guardar cambios' : 'Crear rol'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
