import { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import GoogleAuthButton from '@/components/google-auth-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { routeBackend } from '@/lib/route-compat';
import { useDocumentTitle } from '@/lib/use-document-title';
import AuthHeroLayout from '@/layouts/auth/auth-hero-layout';

/**
 * Mensajes contextuales asociados al `?reason=` que viene del backend cuando
 * redirige rutas legacy (login, register, forgot-password, etc.) hacia el
 * flujo Google OAuth — ver HU #231.
 */
const REASON_MESSAGES: Record<string, string> = {
    email_auth_disabled:
        'El acceso con correo y contraseña ya no está disponible. Entra con tu cuenta de Google.',
    password_reset_disabled:
        'Tu cuenta usa Google para iniciar sesión: no hay contraseña que restablecer.',
};

/**
 * Variantes copy para las páginas legacy que llegan acá. Cada `slot` original
 * (login / register / forgot / reset / verify / confirm) reusa el mismo gate
 * cambiando eyebrow + título + bajada, sin duplicar markup.
 */
export type GoogleOnlyAuthGateVariant =
    | 'login'
    | 'register'
    | 'forgot-password'
    | 'reset-password'
    | 'verify-email'
    | 'confirm-password';

const VARIANT_COPY: Record<
    GoogleOnlyAuthGateVariant,
    { eyebrow: string; title: string; description: string; documentTitle: string }
> = {
    login: {
        eyebrow: 'Bienvenido',
        title: 'Entra con tu cuenta de Google.',
        description:
            'Acceso sin contraseñas, con la identidad que ya usas. En segundos estás dentro de bistro.',
        documentTitle: 'Iniciar sesión',
    },
    register: {
        eyebrow: 'Empieza ahora',
        title: 'Crea tu cuenta con Google.',
        description:
            'Tu identidad Google se usa para abrir la cuenta de bistro. Después eliges empresa y sede.',
        documentTitle: 'Crear cuenta',
    },
    'forgot-password': {
        eyebrow: 'Sin contraseñas',
        title: 'No hay nada que recuperar.',
        description:
            'En bistro no hay contraseñas: entras con Google y listo. Si perdiste el acceso a tu cuenta de Google, recupéralo desde Google directamente.',
        documentTitle: 'Recuperar acceso',
    },
    'reset-password': {
        eyebrow: 'Sin contraseñas',
        title: 'Este enlace ya no aplica.',
        description:
            'bistro usa Google para autenticar. Continúa con tu cuenta Google y entrarás directamente.',
        documentTitle: 'Restablecer contraseña',
    },
    'verify-email': {
        eyebrow: 'Verificación',
        title: 'Tu correo se verifica con Google.',
        description:
            'Cuando entras con Google, tu correo ya queda verificado. No necesitas abrir ningún enlace.',
        documentTitle: 'Verificar correo',
    },
    'confirm-password': {
        eyebrow: 'Acceso seguro',
        title: 'Confirma tu identidad con Google.',
        description:
            'Para continuar con esta acción sensible, vuelve a entrar con tu cuenta de Google.',
        documentTitle: 'Confirmar acceso',
    },
};

const AUTO_REDIRECT_MS = 4000;

interface GoogleOnlyAuthGateProps {
    /** Slot legacy que llamó al gate. Default `login`. */
    variant?: GoogleOnlyAuthGateVariant;
    /**
     * Si `true`, no dispara redirect automático: el usuario debe pulsar el
     * botón. Útil para `verify-email` donde puede que solo informe.
     */
    manualOnly?: boolean;
}

/**
 * Pantalla puente única para las rutas de auth legacy (HU #231).
 *
 * Reusa `AuthHeroLayout` (2-col responsive con `HeroPanel` lime + `HeroHeadline`
 * `font-brand`) — alineado con `FRONTEND_UI_GUIDELINES.md` §6.2b y el DS de
 * marca: paleta `--accent`/`--primary`/`--background`, tipografía `font-brand`,
 * un único bloque lime por pantalla.
 *
 * Cuando el backend redirige (ver `routes/auth.php`) agrega `?reason=` para
 * que mostremos un mensaje contextual antes del rebote a Google OAuth. El
 * redirect automático tras 4s respeta `prefers-reduced-motion: reduce`: si el
 * usuario tiene motion desactivado, no hay cuenta regresiva ni redirect
 * silencioso — debe pulsar el botón.
 */
export default function GoogleOnlyAuthGate({ variant = 'login', manualOnly = false }: GoogleOnlyAuthGateProps) {
    const copy = VARIANT_COPY[variant];
    useDocumentTitle(copy.documentTitle);

    const [searchParams] = useSearchParams();
    const reason = searchParams.get('reason');
    const reasonMessage = reason ? REASON_MESSAGES[reason] : null;

    const prefersReducedMotion = useMemo(() => {
        if (typeof window === 'undefined') return false;
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }, []);

    const autoRedirectEnabled = !manualOnly && !prefersReducedMotion;
    const [remainingMs, setRemainingMs] = useState<number>(autoRedirectEnabled ? AUTO_REDIRECT_MS : 0);
    const [cancelled, setCancelled] = useState(false);

    const cancelledRef = useRef(cancelled);
    cancelledRef.current = cancelled;

    useEffect(() => {
        if (!autoRedirectEnabled || cancelled) {
            return;
        }

        const startedAt = performance.now();
        const intervalId = window.setInterval(() => {
            if (cancelledRef.current) {
                return;
            }
            const elapsed = performance.now() - startedAt;
            const remaining = Math.max(0, AUTO_REDIRECT_MS - elapsed);
            setRemainingMs(remaining);
            if (remaining <= 0) {
                window.clearInterval(intervalId);
                window.location.href = routeBackend('auth.google');
            }
        }, 100);

        const cancelOnKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setCancelled(true);
            }
        };
        window.addEventListener('keydown', cancelOnKey);

        return () => {
            window.clearInterval(intervalId);
            window.removeEventListener('keydown', cancelOnKey);
        };
    }, [autoRedirectEnabled, cancelled]);

    const progressPct = autoRedirectEnabled && !cancelled
        ? Math.round(((AUTO_REDIRECT_MS - remainingMs) / AUTO_REDIRECT_MS) * 100)
        : 0;
    const remainingSec = Math.ceil(remainingMs / 1000);

    return (
        <AuthHeroLayout
            eyebrow={copy.eyebrow}
            title={copy.title}
            description={copy.description}
            panelEyebrow="Acceso por Google"
            panelStats={[
                { label: 'Método', value: 'OAuth 2.0' },
                { label: 'Contraseñas', value: '0' },
                { label: 'Verificación', value: 'Auto' },
            ]}
        >
            <div className="space-y-5">
                {reasonMessage && (
                    <Alert variant="accent">
                        <AlertDescription>{reasonMessage}</AlertDescription>
                    </Alert>
                )}

                <GoogleAuthButton autoFocus />

                {autoRedirectEnabled && !cancelled && (
                    <div
                        role="status"
                        aria-live="polite"
                        className="text-muted-foreground space-y-2 text-xs"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <span>
                                Redirigiendo a Google en <span className="font-medium tabular-nums">{remainingSec}s</span>…
                            </span>
                            <button
                                type="button"
                                onClick={() => setCancelled(true)}
                                className="text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 rounded-sm"
                            >
                                Cancelar
                            </button>
                        </div>
                        <div className="bg-secondary h-1 w-full overflow-hidden rounded-full" aria-hidden="true">
                            <div
                                className="bg-primary h-full transition-[width] duration-100 ease-linear"
                                style={{ width: `${progressPct}%` }}
                            />
                        </div>
                    </div>
                )}

                {(cancelled || !autoRedirectEnabled) && (
                    <p className="text-muted-foreground text-xs">
                        Pulsa <span className="font-medium">Continuar con Google</span> cuando estés listo.
                    </p>
                )}

                <p className="text-muted-foreground text-xs leading-relaxed">
                    Al continuar aceptas los Términos y la Política de privacidad de bistro. Tu sesión queda asociada a la cuenta de Google con la que entres.
                </p>
            </div>
        </AuthHeroLayout>
    );
}
