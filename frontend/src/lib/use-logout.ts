import { useCallback } from 'react';
import { apiClient } from './api-client';
import { clearCachedStores } from './offline/db';

/**
 * Acción de logout del shell SPA.
 *
 * POST a `/api/v1/auth/logout`, limpieza de datos locales de la sesión y hard
 * navigation al home.
 */
export function useLogout(): () => void {
    return useCallback(() => {
        void apiClient
            .post('/api/v1/auth/logout')
            .catch(() => {
                /* el redirect limpia el estado igual */
            })
            .then(async () => {
                // Higiene en dispositivo compartido: sin esto, el cache HTTP de
                // la API (`pos-api-get`) y los snapshots IDB (bootstrap, menú,
                // sesión de caja, carrito) quedaban legibles para el siguiente
                // usuario del equipo. El outbox y pending_orders NO se tocan:
                // son ventas sin sincronizar (ver clearCachedStores).
                const wipes: Promise<unknown>[] = [clearCachedStores().catch(() => undefined)];
                if (typeof caches !== 'undefined') {
                    wipes.push(caches.delete('pos-api-get').catch(() => undefined));
                }
                await Promise.all(wipes);
            })
            .finally(() => {
                window.location.assign('/');
            });
    }, []);
}
