import { useState } from 'react';
import { Link } from 'react-router-dom';

import GoogleAuthButton from '@/components/google-auth-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Turnstile, turnstileEnabled } from '@/components/turnstile';
import AuthHeroLayout from '@/layouts/auth/auth-hero-layout';
import { ApiError, apiClient } from '@/lib/api-client';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { useDocumentTitle } from '@/lib/use-document-title';

interface RegisterErrors {
    first_name?: string;
    last_name?: string;
    email?: string;
    password?: string;
}

/**
 * Registro con correo/contraseña (complementario a Google). Al enviar, el
 * backend crea la cuenta, manda el enlace de verificación y responde con la
 * cookie JWT → navegamos a /verify-email. El registro de empresa queda
 * bloqueado server-side hasta verificar el correo.
 *
 * `website` es un honeypot anti-bots: oculto para humanos (CSS + tabIndex),
 * si llega lleno el backend responde un éxito falso sin crear nada.
 */
export default function Register() {
    useDocumentTitle('Crear cuenta');

    const [form, setForm] = useState({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        website: '',
    });
    const [captcha, setCaptcha] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<RegisterErrors>({});
    const [error, setError] = useState<string | null>(null);

    const set = (field: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value;
        const value = field === 'first_name' || field === 'last_name' ? sanitizePlainText(raw, 80, true, false) : raw;
        setForm((f) => ({ ...f, [field]: value }));
    };

    const canSubmit =
        form.first_name.trim().length >= 2 &&
        form.last_name.trim().length >= 2 &&
        form.email.trim() !== '' &&
        form.password.length >= 8 &&
        form.password_confirmation === form.password &&
        (!turnstileEnabled || captcha !== '');

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (submitting) return;
        setSubmitting(true);
        setError(null);
        setErrors({});
        try {
            const email = form.email.trim();
            const res = await apiClient.post<{ redirect: string; email?: string }>('/api/v1/auth/register', {
                ...form,
                email,
                'cf-turnstile-response': captcha,
            });
            // El registro ya NO auto-loguea (anti-enumeración: sin cookie, la
            // respuesta es idéntica exista o no el correo). Pasamos el email a
            // la pantalla de verificación para poder reenviar el enlace sin
            // sesión. Tras verificar, el usuario entra por /login y continúa.
            const target = res.redirect || '/verify-email';
            window.location.href = `${target}?email=${encodeURIComponent(res.email ?? email)}`;
        } catch (e) {
            if (e instanceof ApiError && e.errors) {
                const flat: RegisterErrors = {};
                for (const [field, messages] of Object.entries(e.errors)) {
                    flat[field as keyof RegisterErrors] = messages[0] ?? '';
                }
                setErrors(flat);
            } else {
                setError('No pudimos crear la cuenta. Intenta de nuevo.');
            }
            setSubmitting(false);
        }
    };

    return (
        <AuthHeroLayout
            eyebrow="Empieza ahora"
            title="Crea tu cuenta."
            description="Con tu correo y una contraseña, o directo con Google. Después verificas el correo y registras tu empresa."
            panelEyebrow="Tu operación, en orden"
        >
            <div className="relative space-y-5">
                <form noValidate onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="first_name">Nombres</Label>
                            <Input
                                id="first_name"
                                autoComplete="given-name"
                                maxLength={80}
                                value={form.first_name}
                                onChange={set('first_name')}
                                aria-invalid={!!errors.first_name}
                                required
                                autoFocus
                            />
                            {errors.first_name && <p className="text-destructive text-xs">{errors.first_name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="last_name">Apellidos</Label>
                            <Input
                                id="last_name"
                                autoComplete="family-name"
                                maxLength={80}
                                value={form.last_name}
                                onChange={set('last_name')}
                                aria-invalid={!!errors.last_name}
                                required
                            />
                            {errors.last_name && <p className="text-destructive text-xs">{errors.last_name}</p>}
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="email">Correo</Label>
                        <Input
                            id="email"
                            type="email"
                            autoComplete="email"
                            inputMode="email"
                            maxLength={255}
                            value={form.email}
                            onChange={set('email')}
                            aria-invalid={!!errors.email}
                            required
                        />
                        {errors.email && <p className="text-destructive text-xs">{errors.email}</p>}
                        <p className="text-muted-foreground text-xs">Te enviaremos un enlace para verificarlo.</p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="password">Contraseña</Label>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                maxLength={200}
                                value={form.password}
                                onChange={set('password')}
                                aria-invalid={!!errors.password}
                                required
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="password_confirmation">Repite la contraseña</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                maxLength={200}
                                value={form.password_confirmation}
                                onChange={set('password_confirmation')}
                                required
                            />
                        </div>
                    </div>
                    {errors.password ? (
                        <p className="text-destructive text-xs">{errors.password}</p>
                    ) : (
                        <p className="text-muted-foreground text-xs">
                            Mínimo 8 caracteres. Rechazamos contraseñas que aparezcan en filtraciones conocidas.
                        </p>
                    )}

                    {/* Honeypot anti-bots: invisible para humanos, los bots lo llenan. */}
                    <div className="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden>
                        <label htmlFor="website">No llenes este campo</label>
                        <input
                            id="website"
                            name="website"
                            type="text"
                            tabIndex={-1}
                            autoComplete="off"
                            value={form.website}
                            onChange={set('website')}
                        />
                    </div>

                    <Turnstile onVerify={setCaptcha} />

                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    <Button type="submit" className="w-full" disabled={submitting || !canSubmit}>
                        {submitting ? 'Creando cuenta…' : 'Crear cuenta'}
                    </Button>
                </form>

                <div className="flex items-center gap-3" aria-hidden>
                    <div className="border-border flex-1 border-t" />
                    <span className="text-muted-foreground text-xs">o</span>
                    <div className="border-border flex-1 border-t" />
                </div>

                <GoogleAuthButton />

                <p className="text-muted-foreground text-sm">
                    ¿Ya tienes cuenta?{' '}
                    <Link to="/login" className="text-primary font-medium underline-offset-4 hover:underline">
                        Inicia sesión
                    </Link>
                </p>
            </div>
        </AuthHeroLayout>
    );
}
