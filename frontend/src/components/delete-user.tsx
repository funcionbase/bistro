import { useApiForm } from '@/hooks/use-api-form';
import { apiClient } from '@/lib/api-client';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import HeadingSmall from '@/components/heading-small';

import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';

/**
 * Bloque destructivo de baja de cuenta en `/settings/profile`.
 *
 * Migrado a tokens DS (`destructive`) y a `BottomSheetDialog`
 * (Sheet inferior en mobile, Dialog en desktop) para que en celular
 * no se sienta como un modal centrado pequeño.
 *
 * Confirmación según tipo de cuenta (mismo contrato que el backend
 * `AccountController::destroy`): con contraseña → `password`; cuentas
 * Google-only (`has_password=false` en `/api/v1/me`, misma fuente que
 * settings/password.tsx) → `confirm_email` con el correo exacto.
 */
export default function DeleteUser() {
    const [open, setOpen] = useState(false);
    const [hasPassword, setHasPassword] = useState<boolean | null>(null);
    const confirmInput = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, reset, errors, clearErrors } = useApiForm({ password: '', confirm_email: '' });

    useEffect(() => {
        let cancelled = false;
        void (async () => {
            try {
                const res = await apiClient.get<{ user: { has_password?: boolean } }>('/api/v1/me');
                if (!cancelled) setHasPassword(!!res.user.has_password);
            } catch {
                if (!cancelled) setHasPassword(true); // fallback conservador: pide contraseña
            }
        })();
        return () => {
            cancelled = true;
        };
    }, []);

    const closeModal = () => {
        clearErrors();
        reset();
        setOpen(false);
    };

    const deleteUser: FormEventHandler = (e) => {
        e.preventDefault();

        void post('/api/v1/account/delete', {
            onSuccess: () => {
                closeModal();
                window.location.assign('/');
            },
            onError: () => confirmInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    const googleOnly = hasPassword === false;

    return (
        <div className="space-y-6">
            <HeadingSmall title="Eliminar cuenta" description="Borra tu cuenta y todos sus recursos asociados" />
            <div className="border-destructive/30 bg-destructive/5 space-y-4 rounded-lg border p-4">
                <div className="text-destructive space-y-0.5">
                    <p className="font-medium">Atención</p>
                    <p className="text-sm">Esta acción es permanente y no se puede revertir.</p>
                </div>

                <Button type="button" variant="destructive" onClick={() => setOpen(true)}>
                    Eliminar cuenta
                </Button>
            </div>

            <BottomSheetDialog isOpen={open} onClose={closeModal} title="¿Eliminar tu cuenta?">
                <p className="text-muted-foreground text-sm">
                    {googleOnly
                        ? 'Al eliminar tu cuenta, todos sus recursos y datos se borrarán permanentemente. Escribe el correo de tu cuenta para confirmar.'
                        : 'Al eliminar tu cuenta, todos sus recursos y datos se borrarán permanentemente. Ingresa tu contraseña para confirmar.'}
                </p>
                <form noValidate className="mt-4 space-y-4" onSubmit={deleteUser}>
                    {googleOnly ? (
                        <div className="grid gap-2">
                            <Label htmlFor="confirm_email" className="sr-only">
                                Correo de tu cuenta
                            </Label>
                            <Input
                                id="confirm_email"
                                type="email"
                                name="confirm_email"
                                ref={confirmInput}
                                value={data.confirm_email}
                                onChange={(e) => setData('confirm_email', e.target.value)}
                                placeholder="Correo de tu cuenta"
                                autoComplete="email"
                                maxLength={255}
                            />
                            <InputError message={errors.confirm_email} />
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="password" className="sr-only">
                                Contraseña
                            </Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                ref={confirmInput}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Contraseña"
                                autoComplete="current-password"
                            />
                            <InputError message={errors.password} />
                        </div>
                    )}

                    <InputError message={errors.general} />

                    <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={closeModal}>
                            Cancelar
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing || hasPassword === null}>
                            Eliminar cuenta
                        </Button>
                    </div>
                </form>
            </BottomSheetDialog>
        </div>
    );
}
