import { getToken, subscribeToken } from '@/lib/token';
import { useEffect, useState } from 'react';

/**
 * Token de sesión observable (#220 — agnóstico Inertia/SPA).
 *
 * El JWT vive en la cookie HttpOnly `flexyflow_jwt`; este hook expone el
 * marcador de sesión (`getToken()`) y reacciona a cambios vía
 * `subscribeToken`. La captura del token inicial (props de Inertia o
 * `?token=` legacy) la hacen los entry points `app.tsx` / `spa/main.tsx`.
 */
export function useToken(): string | null {
    const [token, setLocalToken] = useState<string | null>(getToken());

    useEffect(() => {
        return subscribeToken((newToken) => {
            setLocalToken(newToken);
        });
    }, []);

    return token;
}
