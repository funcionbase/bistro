import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { SanitizedInput } from '@/components/ui/sanitized-input';
import { apiFetch } from '@/lib/api';
import { LoaderCircle, Save } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface UpdatedUser {
    id: string;
    name: string;
    first_name: string | null;
    last_name: string | null;
    email: string;
    cedula: string | null;
}

interface EditPersonalInfoDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Valores actuales del usuario logueado. */
    current: { first_name: string | null; last_name: string | null; email: string; cedula: string | null };
    /** Se invoca con el usuario actualizado (incluye `name` recompuesto por el backend). */
    onUpdated: (user: UpdatedUser) => void;
}

/**
 * Diálogo del DS para que el usuario logueado edite SUS datos personales
 * (nombres + apellidos) desde `/me`.
 *
 * `users.name` es una columna generada (first_name + last_name): por eso se
 * capturan por separado. El correo y la cédula se envían sin cambios — el
 * endpoint `PATCH /api/v1/account/profile` los exige, pero acá no se editan.
 */
export function EditPersonalInfoDialog({ open, onOpenChange, current, onUpdated }: EditPersonalInfoDialogProps) {
    const [firstName, setFirstName] = useState(current.first_name ?? '');
    const [lastName, setLastName] = useState(current.last_name ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [topError, setTopError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    // Resincroniza los inputs cada vez que se (re)abre el diálogo.
    useEffect(() => {
        if (open) {
            setFirstName(current.first_name ?? '');
            setLastName(current.last_name ?? '');
            setErrors({});
            setTopError(null);
        }
    }, [open, current.first_name, current.last_name]);

    function close() {
        if (submitting) return;
        onOpenChange(false);
    }

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (submitting) return;

        const trimmedFirst = firstName.trim();
        const trimmedLast = lastName.trim();

        const localErrors: Record<string, string> = {};
        if (trimmedFirst === '') {
            localErrors.first_name = 'Los nombres son obligatorios.';
        }
        if (trimmedLast === '') {
            localErrors.last_name = 'Los apellidos son obligatorios.';
        }
        if (Object.keys(localErrors).length > 0) {
            setErrors(localErrors);
            return;
        }

        setSubmitting(true);
        setErrors({});
        setTopError(null);

        try {
            const response = await apiFetch('/api/v1/account/profile', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    first_name: trimmedFirst,
                    last_name: trimmedLast,
                    // Sin cambios: el endpoint los exige (email required).
                    email: current.email,
                    cedula: current.cedula,
                }),
            });

            if (response.ok) {
                const body = (await response.json()) as { data: UpdatedUser };
                onUpdated(body.data);
                onOpenChange(false);
                return;
            }

            if (response.status === 422) {
                try {
                    const body = await response.clone().json();
                    const fieldErrors = (body?.errors ?? null) as Record<string, string[]> | null;
                    if (fieldErrors) {
                        const mapped: Record<string, string> = {};
                        for (const [field, messages] of Object.entries(fieldErrors)) {
                            if (messages.length > 0) {
                                mapped[field] = messages[0];
                            }
                        }
                        setErrors(mapped);
                        return;
                    }
                    if (typeof body?.message === 'string') {
                        setTopError(body.message);
                        return;
                    }
                } catch {
                    // dejar caer al genérico.
                }
            }

            setTopError('No fue posible guardar los cambios. Intenta de nuevo.');
        } catch {
            setTopError('Error de conexión. Verifica tu red e intenta de nuevo.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    close();
                } else {
                    onOpenChange(true);
                }
            }}
        >
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Editar información personal</DialogTitle>
                    <DialogDescription>Actualiza tus nombres y apellidos. Tu nombre completo se arma con ambos.</DialogDescription>
                </DialogHeader>
                <form noValidate onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="me-first-name">Nombres</Label>
                        <SanitizedInput
                            id="me-first-name"
                            value={firstName}
                            onChange={setFirstName}
                            maxLength={100}
                            disabled={submitting}
                            autoFocus
                            autoComplete="given-name"
                            placeholder="Nombres"
                        />
                        {errors.first_name && (
                            <p className="text-xs text-[color:var(--color-status-critical)]" role="alert">
                                {errors.first_name}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="me-last-name">Apellidos</Label>
                        <SanitizedInput
                            id="me-last-name"
                            value={lastName}
                            onChange={setLastName}
                            maxLength={100}
                            disabled={submitting}
                            autoComplete="family-name"
                            placeholder="Apellidos"
                        />
                        {errors.last_name && (
                            <p className="text-xs text-[color:var(--color-status-critical)]" role="alert">
                                {errors.last_name}
                            </p>
                        )}
                    </div>

                    {topError && (
                        <p className="text-sm text-[color:var(--color-status-critical)]" role="alert">
                            {topError}
                        </p>
                    )}

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button type="button" variant="outline" onClick={close} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? <LoaderCircle className="mr-1 h-4 w-4 animate-spin" /> : <Save className="mr-1 h-4 w-4" />}
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
