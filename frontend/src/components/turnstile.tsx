import { useEffect, useRef } from 'react';

/**
 * Site key PÚBLICO de Cloudflare Turnstile, horneado en el bundle por Vite
 * (`.env.production`). Vacío = protección desactivada (local/qa sin
 * configurar): el widget no se renderiza y el form no exige token, en paridad
 * con el fail-open del middleware backend.
 */
export const TURNSTILE_SITE_KEY: string =
    (typeof import.meta !== 'undefined' && (import.meta as { env?: Record<string, string> }).env?.VITE_TURNSTILE_SITE_KEY) || '';

export const turnstileEnabled = TURNSTILE_SITE_KEY !== '';

interface TurnstileApi {
    render: (el: HTMLElement, opts: Record<string, unknown>) => string;
    reset: (id?: string) => void;
    remove: (id?: string) => void;
}

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
let scriptPromise: Promise<void> | null = null;

function loadScript(): Promise<void> {
    if (window.turnstile) return Promise.resolve();
    if (scriptPromise) return scriptPromise;
    scriptPromise = new Promise<void>((resolve, reject) => {
        const s = document.createElement('script');
        s.src = SCRIPT_SRC;
        s.async = true;
        s.defer = true;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('turnstile script failed'));
        document.head.appendChild(s);
    });
    return scriptPromise;
}

interface TurnstileProps {
    /** Token válido → habilita el submit. Vacío en expiración/error → lo bloquea. */
    onVerify: (token: string) => void;
    /**
     * Contador que el form incrementa tras un submit fallido para forzar
     * `turnstile.reset()` — el token es de un solo uso y el widget no se
     * regenera solo. El form debe limpiar también su estado de captcha.
     */
    resetSignal?: number;
}

/**
 * Widget de Cloudflare Turnstile (captcha anti-spam). Render explícito para
 * controlar el ciclo de vida en React (montar/desmontar limpio). Si el site
 * key no está configurado, no renderiza nada — el form debe tratar el token
 * como no-requerido.
 */
export function Turnstile({ onVerify, resetSignal = 0 }: TurnstileProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const widgetIdRef = useRef<string | null>(null);
    const onVerifyRef = useRef(onVerify);
    onVerifyRef.current = onVerify;

    useEffect(() => {
        if (resetSignal === 0 || !widgetIdRef.current || !window.turnstile) return;
        try {
            window.turnstile.reset(widgetIdRef.current);
        } catch {
            // widget ya removido — ignorar
        }
    }, [resetSignal]);

    useEffect(() => {
        if (!turnstileEnabled) return;
        let cancelled = false;

        void loadScript()
            .then(() => {
                if (cancelled || !containerRef.current || !window.turnstile) return;
                widgetIdRef.current = window.turnstile.render(containerRef.current, {
                    sitekey: TURNSTILE_SITE_KEY,
                    callback: (token: string) => onVerifyRef.current(token),
                    'expired-callback': () => onVerifyRef.current(''),
                    'error-callback': () => onVerifyRef.current(''),
                    'timeout-callback': () => onVerifyRef.current(''),
                });
            })
            .catch(() => {
                // Script bloqueado/caído: no bloqueamos el form en cliente; el
                // backend es fail-open y los rate limiters siguen protegiendo.
                onVerifyRef.current('');
            });

        return () => {
            cancelled = true;
            if (widgetIdRef.current && window.turnstile) {
                try {
                    window.turnstile.remove(widgetIdRef.current);
                } catch {
                    // widget ya removido — ignorar
                }
            }
        };
    }, []);

    if (!turnstileEnabled) return null;

    return <div ref={containerRef} className="min-h-[65px]" />;
}
