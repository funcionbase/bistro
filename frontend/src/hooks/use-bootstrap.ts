import { apiClient } from '@/lib/api-client';
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
            const data = await apiClient.get<BootstrapResponse>('/api/v1/bootstrap', { signal });
            // Bootstrap OK ⇒ la cookie HttpOnly `flexyflow_jwt` es válida ⇒
            // sesión confirmada. Con el JWT en cookie (invisible a JS) ya no
            // hay `?token=` que capturar tras el OAuth callback, así que el
            // éxito del bootstrap es la ÚNICA señal de "estás autenticado".
            // Sin esto, `useToken()` devuelve null y toda página que hace
            // `if (!token) return` se queda en skeleton para siempre.
            if (data.auth.user) {
                setToken(AUTH_MARKER);
            }
            return data;
        },
        staleTime: 60_000,
    });
}
