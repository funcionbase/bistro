import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { apiFetch } from '@/lib/api';
import type { CompanyRole } from '@/types';
import { AlertCircle, CheckCircle2, LoaderCircle, Mail, Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface InviteUserModalProps {
    roles: CompanyRole[];
    onInvited?: () => void;
}

interface InviteSuccess {
    email: string;
    expiresAt: string | null;
}

export default function InviteUserModal({ roles, onInvited }: InviteUserModalProps) {
    const [open, setOpen] = useState(false);
    const initialRoleId = (() => {
        const defaultRole = roles.find((r) => !r.is_system) ?? roles[0];
        return defaultRole ? String(defaultRole.id) : '';
    })();
    const [email, setEmail] = useState('');
    const [roleId, setRoleId] = useState<string>(initialRoleId);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [success, setSuccess] = useState<InviteSuccess | null>(null);

    const resetForNextInvite = () => {
        setEmail('');
        setRoleId(initialRoleId);
        setSuccess(null);
        setErrors({});
    };

    const handleOpenChange = (next: boolean) => {
        if (processing) return;
        setOpen(next);
        if (!next) {
            resetForNextInvite();
        }
    };

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setErrors({});
        setSuccess(null);
        setProcessing(true);

        try {
            const res = await apiFetch('/api/v1/invitations', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, company_role_id: roleId }),
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

            setSuccess({
                email: data?.invitation?.email ?? email,
                expiresAt: data?.invitation?.expires_at ?? null,
            });
            onInvited?.();
        } catch {
            setErrors({ general: 'Error de conexión. Intenta de nuevo.' });
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="mr-1.5 h-4 w-4" />
                    Invitar usuario
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Invitar usuario</DialogTitle>
                </DialogHeader>

                {success ? (
                    <div className="space-y-4" aria-live="polite">
                        <Alert variant="safe">
                            <CheckCircle2 className="h-4 w-4" />
                            <AlertDescription>
                                Listo. Le enviamos el correo de invitación a <span className="font-semibold break-all">{success.email}</span>.
                            </AlertDescription>
                        </Alert>

                        <div className="bg-muted/40 text-muted-foreground space-y-2 rounded-lg border p-3 text-xs">
                            <div className="flex items-start gap-2">
                                <Mail className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <p>
                                    El invitado entrará iniciando sesión con <span className="text-foreground font-medium break-all">{success.email}</span>. La
                                    aceptación es automática.
                                </p>
                            </div>
                            {success.expiresAt && (
                                <p className="pl-5">
                                    Vigencia hasta{' '}
                                    <span className="text-foreground font-medium">
                                        {new Date(success.expiresAt).toLocaleString('es-CO', {
                                            timeZone: 'America/Bogota',
                                            day: '2-digit',
                                            month: '2-digit',
                                            year: 'numeric',
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </span>
                                </p>
                            )}
                        </div>

                        <DialogFooter className="flex-col gap-2 sm:flex-row">
                            <Button type="button" variant="outline" onClick={resetForNextInvite}>
                                Invitar a otro
                            </Button>
                            <Button type="button" onClick={() => handleOpenChange(false)}>
                                Cerrar
                            </Button>
                        </DialogFooter>
                    </div>
                ) : (
                    <form noValidate onSubmit={handleSubmit} className="space-y-4">
                        {errors.general && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{errors.general}</AlertDescription>
                            </Alert>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="invite-email">Correo electrónico</Label>
                            <Input
                                id="invite-email"
                                type="email"
                                placeholder="correo@ejemplo.com"
                                required
                                autoComplete="email"
                                inputMode="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                disabled={processing}
                                aria-invalid={Boolean(errors.email)}
                                aria-describedby={errors.email ? 'invite-email-error' : undefined}
                            />
                            {errors.email && (
                                <p id="invite-email-error" className="text-destructive text-xs">
                                    {errors.email}
                                </p>
                            )}
                            <p className="text-muted-foreground text-xs">El invitado debe entrar con este mismo correo. La aceptación es automática.</p>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="invite-role">Rol inicial</Label>
                            <Select value={roleId} onValueChange={setRoleId} disabled={processing}>
                                <SelectTrigger id="invite-role">
                                    <SelectValue placeholder="Selecciona un rol" />
                                </SelectTrigger>
                                <SelectContent>
                                    {roles.map((role) => (
                                        <SelectItem key={role.id} value={String(role.id)}>
                                            {role.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.company_role_id && <p className="text-destructive text-xs">{errors.company_role_id}</p>}
                        </div>

                        <DialogFooter className="flex-col gap-2 sm:flex-row">
                            <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={processing}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing || !roleId}>
                                {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : 'Enviar invitación'}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
