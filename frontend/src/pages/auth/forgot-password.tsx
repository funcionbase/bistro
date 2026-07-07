import { useState } from 'react';
import { Link } from 'react-router-dom';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthHeroLayout from '@/layouts/auth/auth-hero-layout';
import { ApiError, apiClient } from '@/lib/api-client';
import { useDocumentTitle } from '@/lib/use-document-title';

/**
 * Olvidé mi contraseña. También es el camino para que una cuenta creada con
 * Google FIJE contraseña por primera vez (acceso dual con el mismo correo).
 * La respuesta del backend es siempre genérica (anti-enumeración).
 */
export default function ForgotPassword() {
    useDocumentTitle('Recuperar contraseña');

    const [email, setEmail] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [sent, setSent] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (submitting) return;
        setSubmitting(true);
        setError(null);
        try {
            const res = await apiClient.post<{ message?: string }>('/api/v1/auth/forgot-password', { email: email.trim() });
            setSent(res.message ?? 'Si el correo existe, te enviamos instrucciones para restablecer la contraseña.');
        } catch (e) {
            setError(e instanceof ApiError && e.message ? e.message : 'No pudimos procesar la solicitud. Intenta de nuevo.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AuthHeroLayout
            eyebrow="Recuperar acceso"
            title="Restablece tu contraseña."
            description="Te enviamos un enlace al correo de tu cuenta. Si tu cuenta entraba solo con Google, este mismo camino te deja crear una contraseña."
            panelEyebrow="Acceso seguro"
        >
            <div className="space-y-5">
                {sent ? (
                    <>
                        <Alert variant="accent">
                            <AlertDescription>{sent}</AlertDescription>
                        </Alert>
                        <p className="text-muted-foreground text-xs">
                            Revisa también la carpeta de spam. El enlace vence en 60 minutos.
                        </p>
                    </>
                ) : (
                    <form noValidate onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="email">Correo de tu cuenta</Label>
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

                        {error && (
                            <Alert variant="destructive">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        <Button type="submit" className="w-full" disabled={submitting || email.trim() === ''}>
                            {submitting ? 'Enviando…' : 'Enviarme el enlace'}
                        </Button>
                    </form>
                )}

                <p className="text-muted-foreground text-sm">
                    <Link to="/login" className="text-primary font-medium underline-offset-4 hover:underline">
                        Volver a iniciar sesión
                    </Link>
                </p>
            </div>
        </AuthHeroLayout>
    );
}
