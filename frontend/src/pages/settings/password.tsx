import { useEffect, useRef, useState, type FormEventHandler } from 'react';
import { ShieldCheck } from 'lucide-react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/layout';
import { ApiError, apiClient } from '@/lib/api-client';

/**
 * Settings · Contraseña (acceso dual).
 *
 * Dos modos según si la cuenta ya tiene contraseña (`/me` → `has_password`):
 *  - **Sin contraseña** (cuenta creada con Google): fija la primera. No pide
 *    contraseña actual — el usuario ya está autenticado por su sesión.
 *  - **Con contraseña**: cambio normal, pide la actual.
 *
 * El backend (`PUT /api/v1/account/password`) valida igual: `current_password`
 * solo es obligatoria si la cuenta ya tenía contraseña.
 */
export default function Password() {
    const [hasPassword, setHasPassword] = useState<boolean | null>(null);
    const [current, setCurrent] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);
    const passwordRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        let cancelled = false;
        void (async () => {
            try {
                const res = await apiClient.get<{ user: { has_password?: boolean } }>('/api/v1/me');
                if (!cancelled) setHasPassword(!!res.user.has_password);
            } catch {
                if (!cancelled) setHasPassword(true); // fallback conservador: pide la actual
            }
        })();
        return () => {
            cancelled = true;
        };
    }, []);

    const canSubmit = password.length >= 8 && confirmation === password && (hasPassword === false || current !== '');

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        if (processing || !canSubmit) return;
        setProcessing(true);
        setErrors({});
        setDone(false);
        try {
            await apiClient.put('/api/v1/account/password', {
                current_password: hasPassword ? current : null,
                password,
                password_confirmation: confirmation,
            });
            setDone(true);
            setHasPassword(true);
            setCurrent('');
            setPassword('');
            setConfirmation('');
        } catch (err) {
            if (err instanceof ApiError && err.errors) {
                const flat: Record<string, string> = {};
                for (const [field, messages] of Object.entries(err.errors)) {
                    flat[field] = messages[0] ?? '';
                }
                setErrors(flat);
                passwordRef.current?.focus();
            } else {
                setErrors({ general: 'No pudimos guardar la contraseña. Intenta de nuevo.' });
            }
        } finally {
            setProcessing(false);
        }
    };

    const firstTime = hasPassword === false;

    return (
        <PageShell title="Contraseña">
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={firstTime ? 'Crear contraseña' : 'Cambiar contraseña'}
                        description={
                            firstTime
                                ? 'Tu cuenta entra con Google. Crea una contraseña para poder entrar también con tu correo — es la misma cuenta.'
                                : 'Actualiza la contraseña con la que inicias sesión.'
                        }
                    />

                    {firstTime && (
                        <Alert variant="accent">
                            <ShieldCheck className="h-4 w-4" />
                            <AlertDescription>
                                Podrás seguir entrando con Google como siempre; esto solo agrega el acceso con correo y contraseña.
                            </AlertDescription>
                        </Alert>
                    )}

                    <form noValidate onSubmit={submit} className="max-w-md space-y-5">
                        {hasPassword !== false && (
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">Contraseña actual</Label>
                                <Input
                                    id="current_password"
                                    type="password"
                                    autoComplete="current-password"
                                    maxLength={200}
                                    value={current}
                                    onChange={(e) => setCurrent(e.target.value)}
                                    aria-invalid={!!errors.current_password}
                                />
                                <InputError message={errors.current_password} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="password">{firstTime ? 'Contraseña' : 'Nueva contraseña'}</Label>
                            <Input
                                ref={passwordRef}
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                maxLength={200}
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                aria-invalid={!!errors.password}
                            />
                            {errors.password ? (
                                <InputError message={errors.password} />
                            ) : (
                                <p className="text-muted-foreground text-xs">
                                    Mínimo 8 caracteres. Rechazamos contraseñas que aparezcan en filtraciones conocidas.
                                </p>
                            )}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Repite la contraseña</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                maxLength={200}
                                value={confirmation}
                                onChange={(e) => setConfirmation(e.target.value)}
                            />
                        </div>

                        {errors.general && (
                            <Alert variant="destructive">
                                <AlertDescription>{errors.general}</AlertDescription>
                            </Alert>
                        )}
                        {done && (
                            <Alert variant="accent">
                                <AlertDescription>
                                    {firstTime ? 'Contraseña creada. Ya puedes entrar con tu correo.' : 'Contraseña actualizada.'}
                                </AlertDescription>
                            </Alert>
                        )}

                        <Button type="submit" disabled={processing || hasPassword === null || !canSubmit}>
                            {processing ? 'Guardando…' : firstTime ? 'Crear contraseña' : 'Cambiar contraseña'}
                        </Button>
                    </form>
                </div>
            </SettingsLayout>
        </PageShell>
    );
}
