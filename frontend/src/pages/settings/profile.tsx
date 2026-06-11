import { Transition } from '@headlessui/react';
import { FormEventHandler } from 'react';

import DeleteUser from '@/components/delete-user';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SanitizedInput } from '@/components/ui/sanitized-input';
import { useApiForm } from '@/hooks/use-api-form';
import SettingsLayout from '@/layouts/settings/layout';
import { apiClient } from '@/lib/api-client';
import { useSharedData } from '@/lib/shared-data';


export default function Profile() {
    const { auth } = useSharedData();
    const user = auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useApiForm({
        first_name: (user.first_name as string | null | undefined) ?? '',
        last_name: (user.last_name as string | null | undefined) ?? '',
        email: user.email,
        cedula: (user.cedula as string | null | undefined) ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        void patch('/api/v1/account/profile');
    };

    return (
        <PageShell title="Perfil">
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Información del perfil" description="Actualiza tu nombre, cédula y correo" />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="first_name">Nombres</Label>

                                <SanitizedInput
                                    id="first_name"
                                    className="mt-1 block w-full"
                                    value={data.first_name}
                                    onChange={(value) => setData('first_name', value)}
                                    required
                                    maxLength={100}
                                    autoComplete="given-name"
                                    placeholder="Nombres"
                                />

                                <InputError className="mt-2" message={errors.first_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="last_name">Apellidos</Label>

                                <SanitizedInput
                                    id="last_name"
                                    className="mt-1 block w-full"
                                    value={data.last_name}
                                    onChange={(value) => setData('last_name', value)}
                                    required
                                    maxLength={100}
                                    autoComplete="family-name"
                                    placeholder="Apellidos"
                                />

                                <InputError className="mt-2" message={errors.last_name} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="cedula">Cédula</Label>

                            <Input
                                id="cedula"
                                inputMode="numeric"
                                pattern="[0-9]{5,20}"
                                className="mt-1 block w-full"
                                value={data.cedula}
                                onChange={(e) => setData('cedula', e.target.value.replace(/[^0-9]/g, ''))}
                                autoComplete="off"
                                placeholder="Solo dígitos (5 a 20)"
                            />

                            <InputError className="mt-2" message={errors.cedula} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Correo electrónico</Label>

                            <Input
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                autoComplete="username"
                                placeholder="correo@ejemplo.com"
                            />

                            <InputError className="mt-2" message={errors.email} />
                        </div>

                        {user.email_verified_at === null && (
                            <div className="bg-muted/40 border-border space-y-2 rounded-lg border p-3">
                                <p className="text-foreground text-sm">
                                    Tu correo aún no está verificado.{' '}
                                    <button
                                        type="button"
                                        onClick={() => void apiClient.post('/api/v1/auth/verification-notification')}
                                        className="text-primary hover:text-primary/80 focus-visible:ring-ring rounded-sm underline focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                    >
                                        Reenviar correo de verificación
                                    </button>
                                </p>
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Guardar</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-muted-foreground text-sm">Perfil actualizado</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <DeleteUser />
            </SettingsLayout>
        </PageShell>
    );
}
