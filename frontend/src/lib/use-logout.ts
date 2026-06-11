import { useCallback } from 'react';
import { apiClient } from './api-client';

/**
 * Acción de logout del shell SPA (#220).
 *
 * POST a `/api/v1/auth/logout` y hard navigation al home.
 */
export function useLogout(): () => void {
    return useCallback(() => {
        void apiClient
            .post('/api/v1/auth/logout')
            .catch(() => {
                /* el redirect limpia el estado igual */
            })
            .finally(() => {
                window.location.assign('/');
            });
    }, []);
}
