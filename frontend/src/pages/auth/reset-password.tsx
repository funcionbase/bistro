import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthHeroLayout from '@/layouts/auth/auth-hero-layout';
import { ApiError, apiClient } from '@/lib/api-client';
import { useDocumentTitle } from '@/lib/use-document-title';

/**
 * Aplica el token del correo de reset (`/reset-password/{token}?email=...`).
 * Además de restablecer, sirve para FIJAR la primera contraseña de una
 * cuenta creada con Google. El backend marca el correo como verificado
 * (el enlace probó posesión).
 */
export default function ResetPassword() {
    useDocumentTitle('Nueva contraseña');
    const { token } = useParams<{ token: string }>();
    const [searchParams] = useSearchParams();

    const [email, setEmail] = useState(searchParams.get('email') ?? '');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const canSubmit = email.trim() !== '' && password.length >= 8 && confirmation === password && !!token;

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (submitting || !token) return;
        setSubmitting(true);
        setError(null);
        try {
            const res = await apiClient.post<{ redirect?: string }>('/api/v1/auth/reset-password', {
                token,
                email: email.trim(),
                password,
                password_confirmation: confirmation,
            });
            window.location.href = res.redirect ?? '/login?reset=1';
        } catch (e) {
            setError(
                e instanceof ApiError && e.message
                    ? e.message
                    : 'No pudimos restablecer la contraseña. Pide un enlace nuevo e intenta otra vez.',
            );
            setSubmitting(false);
        }
    };

    return (
        <AuthHeroLayout
            eyebrow="Recuperar acceso"
            title="Crea tu nueva contraseña."
            description="Elige una contraseña nueva para tu cuenta. Después inicias sesión con ella (tu acceso con Google sigue funcionando igual)."
            panelEyebrow="Acceso seguro"
        >
            <div className="space-y-5">
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
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="password">Nueva contraseña</Label>
                        <Input
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            maxLength={200}
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                            autoFocus
                        />
                        <p className="text-muted-foreground text-xs">
                            Mínimo 8 caracteres. Rechazamos contraseñas que aparezcan en filtraciones conocidas.
                        </p>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="password_confirmation">Repite la contraseña</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            maxLength={200}
                            value={confirmation}
                            onChange={(e) => setConfirmation(e.target.value)}
                            required
                        />
                    </div>

                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    <Button type="submit" className="w-full" disabled={submitting || !canSubmit}>
                        {submitting ? 'Guardando…' : 'Guardar contraseña'}
                    </Button>
                </form>

                <p className="text-muted-foreground text-sm">
                    ¿El enlace venció?{' '}
                    <Link to="/forgot-password" className="text-primary font-medium underline-offset-4 hover:underline">
                        Pide uno nuevo
                    </Link>
                </p>
            </div>
        </AuthHeroLayout>
    );
}
