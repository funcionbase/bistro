import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';

import GoogleAuthButton from '@/components/google-auth-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Turnstile, turnstileEnabled } from '@/components/turnstile';
import AuthHeroLayout from '@/layouts/auth/auth-hero-layout';
import { ApiError, apiClient } from '@/lib/api-client';
import { useDocumentTitle } from '@/lib/use-document-title';

/**
 * Login con correo/contraseña + Google (acceso dual). Reemplaza el
 * `GoogleOnlyAuthGate` de HU #231.
 *
 * El POST responde `{ redirect }` con la cookie JWT ya seteada; navegamos con
 * recarga completa para que el bootstrap arranque con la sesión fresca (mismo
 * efecto que el redirect server-side del callback de Google). El backend
 * decide el destino: verificación pendiente, enrollment a medio camino,
 * dashboard o selector de empresa — el usuario siempre retoma donde quedó.
 */
export default function Login() {
    useDocumentTitle('Iniciar sesión');
    const [searchParams] = useSearchParams();

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [captcha, setCaptcha] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Avisos contextuales de flujos previos (verificación, reset, enlaces).
    const notice = searchParams.get('verified')
        ? 'Tu correo quedó verificado. Inicia sesión para continuar.'
        : searchParams.get('reset')
          ? 'Tu contraseña quedó actualizada. Inicia sesión con ella.'
          : searchParams.get('verify_error')
            ? 'El enlace de verificación no es válido o expiró. Inicia sesión y pide uno nuevo.'
            : null;

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (submitting) return;
        setSubmitting(true);
        setError(null);
        try {
            const res = await apiClient.post<{ redirect: string }>('/api/v1/auth/login', {
                email: email.trim(),
                password,
                'cf-turnstile-response': captcha,
            });
            window.location.href = res.redirect || '/dashboard';
        } catch (e) {
            setError(e instanceof ApiError && e.message ? e.message : 'No pudimos iniciar sesión. Intenta de nuevo.');
            setSubmitting(false);
        }
    };

    return (
        <AuthHeroLayout
            eyebrow="Bienvenido"
            title="Entra a tu panel."
            description="Con tu correo y contraseña, o con tu cuenta de Google — ambos llevan a la misma cuenta."
            panelEyebrow="Acceso seguro"
        >
            <div className="space-y-5">
                {notice && (
                    <Alert variant="accent">
                        <AlertDescription>{notice}</AlertDescription>
                    </Alert>
                )}

                <form noValidate onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="email">Correo</Label>
                        <Input
                            id="email"
                            type="email"
                            autoComplete="email"
                            inputMode="email"
                            maxLength={255}
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                            autoFocus
                        />
                    </div>

                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between">
                            <Label htmlFor="password">Contraseña</Label>
                            <Link to="/forgot-password" className="text-primary text-xs underline-offset-4 hover:underline">
                                ¿La olvidaste?
                            </Link>
                        </div>
                        <Input
                            id="password"
                            type="password"
                            autoComplete="current-password"
                            maxLength={200}
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                        />
                    </div>

                    <Turnstile onVerify={setCaptcha} />

                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    <Button
                        type="submit"
                        className="w-full"
                        disabled={submitting || email.trim() === '' || password === '' || (turnstileEnabled && captcha === '')}
                    >
                        {submitting ? 'Entrando…' : 'Iniciar sesión'}
                    </Button>
                </form>

                <div className="flex items-center gap-3" aria-hidden>
                    <div className="border-border flex-1 border-t" />
                    <span className="text-muted-foreground text-xs">o</span>
                    <div className="border-border flex-1 border-t" />
                </div>

                <GoogleAuthButton />

                <p className="text-muted-foreground text-sm">
                    ¿No tienes cuenta?{' '}
                    <Link to="/register" className="text-primary font-medium underline-offset-4 hover:underline">
                        Regístrate
                    </Link>
                </p>

                <p className="text-muted-foreground text-xs leading-relaxed">
                    Si tu cuenta entró siempre con Google, puedes crear una contraseña desde{' '}
                    <Link to="/forgot-password" className="underline-offset-4 hover:underline">
                        ¿La olvidaste?
                    </Link>{' '}
                    con el mismo correo — ambos accesos llevan a tu misma cuenta.
                </p>
            </div>
        </AuthHeroLayout>
    );
}
