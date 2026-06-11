import { useApiForm } from '@/hooks/use-api-form';
import { FormEventHandler, useRef, useState } from 'react';

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
 */
export default function DeleteUser() {
    const [open, setOpen] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, reset, errors, clearErrors } = useApiForm({ password: '' });

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
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

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
                    Al eliminar tu cuenta, todos sus recursos y datos se borrarán permanentemente. Ingresa tu contraseña para confirmar.
                </p>
                <form className="mt-4 space-y-4" onSubmit={deleteUser}>
                    <div className="grid gap-2">
                        <Label htmlFor="password" className="sr-only">
                            Contraseña
                        </Label>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="Contraseña"
                            autoComplete="current-password"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={closeModal}>
                            Cancelar
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            Eliminar cuenta
                        </Button>
                    </div>
                </form>
            </BottomSheetDialog>
        </div>
    );
}
