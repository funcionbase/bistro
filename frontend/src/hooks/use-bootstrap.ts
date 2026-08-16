import { apiClient, ApiError } from '@/lib/api-client';
import { notifyIntroReady } from '@/lib/intro';
import { getCachedBootstrap, putCachedBootstrap } from '@/lib/offline/db';
import { AUTH_MARKER, setToken } from '@/lib/token';
import {
    type Bank,
    type Branch,
    type Company,
    type EmployeeStatusesConfig,
    type OrderStatusesConfig,
    type PaymentMethodsConfig,
    type PrintingConfig,
    type RbacActionDescriptor,
    type User,
} from '@/types';
import { useQuery, type UseQueryResult } from '@tanstack/react-query';

/**
 * Contrato del endpoint GET /api/v1/bootstrap.
 *
 * Provee el contexto global de la SPA (sesión, empresa/sede activa, permisos,
 * catálogos). Reutiliza los tipos canónicos de `@/types` para proyectar a
 * `SharedData` sin casts.
 */
export interface BootstrapResponse {
    auth: {
        user: User | null;
    };
    needsProfileCompletion: boolean;
    companies: Company[];
    activeCompany: Company | null;
    branches: Branch[];
    activeBranch: Branch | null;
    role: RoleSummary | null;
    permissions: string[];
    orderStatuses: OrderStatusesConfig;
    paymentMethods: PaymentMethodsConfig;
    rbacActions: RbacActionDescriptor[];
    employeeStatuses: EmployeeStatusesConfig;
    vapidPublicKey: string | null;
    /** Measurement ID de GA4 (`G-XXXXXXXXXX`). `null` => GA4 deshabilitado (no se carga gtag.js). */
    gaMeasurementId: string | null;
    printingConfig: PrintingConfig;
    availableBanks: Bank[];
    legalUrls: LegalUrls;
    /** Versión real del backend que respondió (composer.json en runtime). Opcional: backends previos a 1.30.2 no la envían. */
    versions?: { backend: string };
}

export interface LegalUrls {
    terms: string;
    privacy: string;
    contract: string;
}

export interface RoleSummary {
    id: string;
    name: string;
    is_system: boolean;
}

export const BOOTSTRAP_QUERY_KEY = ['bootstrap'] as const;

/**
 * Key reservada en el store `cached_bootstrap` (keyPath `company_nit`) para el
 * snapshot COMPLETO del último bootstrap exitoso. No colisiona con NITs reales
 * (solo dígitos) ni con el snapshot parcial de RBAC que escribe
 * `app-sidebar-layout` bajo el NIT de la empresa activa.
 */
const OFFLINE_BOOTSTRAP_KEY = '__bootstrap__';

/**
 * Hook canónico que carga el contexto global de la SPA.
 *
 * Llamarlo en el root de la app SPA tras login. Para invalidar
 * (ej: tras `switchCompany` o `switchBranch`), usar:
 *   `queryClient.invalidateQueries({ queryKey: BOOTSTRAP_QUERY_KEY })`.
 */
export function useBootstrap(): UseQueryResult<BootstrapResponse> {
    return useQuery<BootstrapResponse>({
        queryKey: BOOTSTRAP_QUERY_KEY,
        queryFn: async ({ signal }) => {
            let data: BootstrapResponse;
            try {
                data = await apiClient.get<BootstrapResponse>('/api/v1/bootstrap', { signal });
            } catch (error) {
                // Errores HTTP (ApiError: 401/403/5xx) y aborts re-lanzan: la
                // sesión inválida la maneja el layout. Solo un fallo de RED
                // (fetch rechaza: sin conexión) cae al snapshot offline — sin
                // esto, recargar /caja sin red expulsaba a la landing.
                if (error instanceof ApiError || (error instanceof DOMException && error.name === 'AbortError')) {
                    throw error;
                }
                const cached = await getCachedBootstrap(OFFLINE_BOOTSTRAP_KEY).catch(() => undefined);
                if (!cached) {
                    throw error;
                }
                const snapshot = cached.payload as BootstrapResponse;
                if (snapshot.auth.user) {
                    setToken(AUTH_MARKER);
                }
                notifyIntroReady();
                return snapshot;
            }
            // Bootstrap OK ⇒ la cookie HttpOnly `bistro_jwt` es válida ⇒
            // sesión confirmada. Con el JWT en cookie (invisible a JS) ya no
            // hay `?token=` que capturar tras el OAuth callback, así que el
            // éxito del bootstrap es la ÚNICA señal de "estás autenticado".
            // Sin esto, `useToken()` devuelve null y toda página que hace
            // `if (!token) return` se queda en skeleton para siempre.
            if (data.auth.user) {
                setToken(AUTH_MARKER);
                // Snapshot completo para recargas offline (best-effort).
                void putCachedBootstrap(OFFLINE_BOOTSTRAP_KEY, data).catch(() => undefined);
            }
            // Datos globales listos → si el intro del shell sigue en pantalla
            // (carga post-login), puede iniciar su salida.
            notifyIntroReady();
            return data;
        },
        staleTime: 60_000,
    });
}
