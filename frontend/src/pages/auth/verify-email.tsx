import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AuthHeroLayout from '@/layouts/auth/auth-hero-layout';
import { ApiError, apiClient } from '@/lib/api-client';
import { useDocumentTitle } from '@/lib/use-document-title';
import { MailCheck } from 'lucide-react';

/**
 * Pantalla "verifica tu correo". Dos modos:
 *
 *  - **Con sesión** (usuario sin verificar que INICIÓ SESIÓN): hace poll del
 *    estado cada 5s + al volver el foco; al verificar avanza sola a
 *    `/enrollment/user` (continuidad). Reenvío vía endpoint con JWT.
 *  - **Sin sesión** (justo después del registro, que ya no auto-loguea por
 *    anti-enumeración): informativo. El email viene por `?email=`. No hace
 *    poll (sin sesión no hay a quién consultar sin filtrar existencia);
 *    reenvía vía endpoint público genérico. Al tocar el enlace del correo el
 *    usuario cae en `/login?verified=1` y continúa el flujo.
 */
export default function VerifyEmail() {
    useDocumentTitle('Verifica tu correo');
    const [searchParams] = useSearchParams();
    const emailFromUrl = searchParams.get('email');

    // null = aún no sabemos; true = hay sesión; false = sin sesión (post-registro).
    const [hasSession, setHasSession] = useState<boolean | null>(null);
    const [email, setEmail] = useState<string | null>(emailFromUrl);
    const [resending, setResending] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const verifiedRef = useRef(false);

    const checkStatus = useCallback(async () => {
        if (verifiedRef.current) return;
        try {
            const res = await apiClient.get<{ email: string; verified: boolean }>('/api/v1/auth/verification/status');
            setHasSession(true);
            setEmail(res.email);
            if (res.verified) {
                verifiedRef.current = true;
                window.location.href = '/enrollment/user';
            }
        } catch (e) {
            // 401 → sin sesión (post-registro): modo informativo con el email de la URL.
            if (e instanceof ApiError && e.status === 401) {
                setHasSession(false);
                return;
            }
            // Otros errores (red): reintenta en el próximo tick si hay sesión.
        }
    }, []);

    useEffect(() => {
        void checkStatus();
    }, [checkStatus]);

    // Poll SOLO en modo con sesión — sin sesión no podemos consultar estado sin
    // filtrar existencia del correo.
    useEffect(() => {
        if (hasSession !== true) return;
        const interval = window.setInterval(() => void checkStatus(), 5000);
        const onFocus = () => void checkStatus();
        window.addEventListener('focus', onFocus);
        return () => {
            window.clearInterval(interval);
            window.removeEventListener('focus', onFocus);
        };
    }, [hasSession, checkStatus]);

    const resend = async () => {
        setResending(true);
        setNotice(null);
        setError(null);
        try {
            if (hasSession) {
                const res = await apiClient.post<{ message?: string }>('/api/v1/auth/verification/resend', {});
                setNotice(res.message ?? 'Te enviamos un nuevo enlace de verificación.');
            } else if (email) {
                const res = await apiClient.post<{ message?: string }>('/api/v1/auth/verification/resend-public', { email });
                setNotice(res.message ?? 'Si tu correo está pendiente de verificación, te enviamos un nuevo enlace.');
            } else {
                setError('No sabemos a qué correo reenviar. Vuelve a registrarte o inicia sesión.');
            }
        } catch (e) {
            setError(e instanceof ApiError && e.message ? e.message : 'No pudimos reenviar el enlace. Intenta de nuevo.');
        } finally {
            setResending(false);
        }
    };

    return (
        <AuthHeroLayout
            eyebrow="Verificación"
            title="Revisa tu correo."
            description={
                email
                    ? `Te enviamos un enlace de verificación a ${email}. Tócalo para continuar con el registro de tu empresa.`
                    : 'Te enviamos un enlace de verificación. Tócalo para continuar con el registro de tu empresa.'
            }
            panelEyebrow="Un paso más"
            panelStats={[
                { label: 'Enlace', value: '60 min' },
                { label: 'Registro de empresa', value: 'Tras verificar' },
            ]}
        >
            <div className="space-y-5">
                <div className="border-border bg-card flex items-start gap-3 rounded-2xl border p-4">
                    <div className="bg-primary/10 text-primary flex h-10 w-10 shrink-0 items-center justify-center rounded-full">
                        <MailCheck className="h-5 w-5" aria-hidden />
                    </div>
                    <div className="text-sm">
                        <p className="text-foreground font-medium">¿No te llegó?</p>
                        <p className="text-muted-foreground">
                            Revisa la carpeta de spam. El enlace vence en 60 minutos — puedes pedir uno nuevo abajo.
                        </p>
                    </div>
                </div>

                {notice && (
                    <Alert variant="accent">
                        <AlertDescription>{notice}</AlertDescription>
                    </Alert>
                )}
                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <Button type="button" className="w-full" onClick={() => void resend()} disabled={resending}>
                    {resending ? 'Enviando…' : 'Reenviar enlace de verificación'}
                </Button>

                {hasSession === false && (
                    <p className="text-muted-foreground text-sm">
                        Cuando verifiques, entra desde{' '}
                        <Link to="/login" className="text-primary font-medium underline-offset-4 hover:underline">
                            Iniciar sesión
                        </Link>{' '}
                        para continuar.
                    </p>
                )}

                <p className="text-muted-foreground text-xs leading-relaxed">
                    Hasta que verifiques el correo no es posible registrar tu empresa. Si el correo está mal escrito, regístrate de
                    nuevo con el correcto o entra con Google.
                </p>
            </div>
        </AuthHeroLayout>
    );
}
