import { QueryClient } from '@tanstack/react-query';
import { ApiError } from './api-client';

/**
 * Cliente global de TanStack Query (Fase 0.4).
 *
 * Defaults conservadores: refetch sólo en re-mount, no en window-focus
 * (la app es operativa, no informativa — no queremos polling agresivo
 * gratis). Las páginas críticas que necesitan freshness real (kanban
 * de órdenes, KDS, comanda) configuran su propio `refetchInterval`
 * por hook.
 *
 * 401 no se reintenta — el redirect ya lo dispara `apiFetch`.
 */
export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            gcTime: 5 * 60_000,
            refetchOnWindowFocus: false,
            retry: (failureCount, error) => {
                if (error instanceof ApiError) {
                    if (error.status === 401 || error.status === 403 || error.status === 422) {
                        return false;
                    }
                }
                return failureCount < 2;
            },
        },
        mutations: {
            retry: false,
        },
    },
});
