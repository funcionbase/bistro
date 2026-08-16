import { apiClient } from '@/lib/api-client';
import { useSharedData } from '@/lib/shared-data';
import { useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { createContext, useContext, useEffect, useMemo, type ReactNode } from 'react';

/**
 * Contrato del endpoint GET /api/v1/me/active-context.
 *
 * Devuelve el vertical de la sede activa + capabilities resueltas + labels
 * dinámicos + prep_areas. El frontend lo consume después del bootstrap y
 * dispara invalidación al cambiar de sede activa.
 */
export interface BusinessContextResponse {
    branch: {
        id: string;
        name: string;
        slug: string;
        is_default: boolean;
    };
    business_type: {
        slug: string;
        label_es: string;
        label_en: string;
        icon_key: string | null;
    } | null;
    capabilities: BusinessCapabilities;
    labels: BusinessLabels;
    prep_areas: PrepArea[];
}

/**
 * Flags canónicos. Mantener sincronizado con
 * `App\Services\BusinessCapabilityService::DEFAULT_FLAGS`.
 */
export interface BusinessCapabilities {
    pos_orders: boolean;
    counter_orders: boolean;
    tables: boolean;
    kds: boolean;
    prep_areas: boolean;
    delivery: boolean;
    recipes: boolean;
    inventory: boolean;
    reservations: boolean;
    catering_scheduling: boolean;
    multi_menu: boolean;
    [key: string]: boolean;
}

export interface BusinessLabels {
    business_type: string | null;
    business_type_label: string | null;
    roles: Record<string, string>;
    order_statuses: Record<string, string>;
    modules: Record<string, string>;
}

export interface PrepArea {
    id: string;
    slug: string;
    label: string;
    color: string;
    icon_key: string | null;
    display_order: number;
}

export const BUSINESS_CONTEXT_QUERY_KEY = ['business-context'] as const;

/**
 * Hook canónico que carga el contexto de negocio para la sede activa. Se
 * dispara automáticamente solo cuando hay sede activa en SharedData.
 */
export function useActiveContextQuery(branchId: string | null): UseQueryResult<BusinessContextResponse> {
    return useQuery<BusinessContextResponse>({
        queryKey: [...BUSINESS_CONTEXT_QUERY_KEY, branchId],
        queryFn: async ({ signal }) =>
            apiClient.get<BusinessContextResponse>('/api/v1/me/active-context', { signal }),
        enabled: branchId !== null,
        staleTime: 60_000,
    });
}

const BusinessContext = createContext<BusinessContextResponse | null>(null);

/**
 * Lee el contexto de negocio. Devuelve `null` si la sede activa no tiene
 * vertical asignado o el hook se monta antes de cargar.
 */
export function useBusinessContext(): BusinessContextResponse | null {
    return useContext(BusinessContext);
}

/**
 * Atajo para resolver capabilities. Devuelve `false` si la capability no está
 * habilitada o si el contexto aún no se cargó (fail-safe para gates).
 */
export function useBusinessCapability(flag: keyof BusinessCapabilities | string): boolean {
    const ctx = useBusinessContext();
    if (!ctx) return false;
    return Boolean(ctx.capabilities[flag]);
}

/**
 * Atajo para resolver labels. Devuelve el slug recibido si el label no se
 * encuentra (preserva consistencia visual ante context aún no resuelto).
 */
export function useBusinessLabel(
    bucket: 'roles' | 'order_statuses' | 'modules',
    slug: string,
    fallback?: string,
): string {
    const ctx = useBusinessContext();
    return ctx?.labels[bucket]?.[slug] ?? fallback ?? slug;
}

/**
 * Devuelve las prep_areas de la sede activa (vacío si no hay).
 */
export function usePrepAreas(): PrepArea[] {
    const ctx = useBusinessContext();
    return ctx?.prep_areas ?? [];
}

/**
 * Provee el contexto de negocio resuelto. Se monta dentro de
 * `<SpaSharedDataBridge>` para que la sede activa esté disponible.
 *
 * Si la sede activa cambia (por `switchBranch`), el contexto se recarga
 * automáticamente porque la queryKey incluye `branchId` — la react-query
 * dispara una request nueva al endpoint y los consumidores (`useBusinessCapability`,
 * `useBusinessLabel`, etc.) re-renderizan con las capabilities/labels frescas
 * del nuevo vertical.
 *
 * Defensa contra parpadeo: si la `query.data` viene rezagada del branch
 * anterior (puede ocurrir entre invalidación e idle si `keepPreviousData`
 * está implícito), la descartamos exponiendo `null` hasta que la query del
 * branch actual resuelva — preferimos "mostrar todo" momentáneo a mostrar
 * opciones del vertical equivocado.
 */
export function BusinessProvider({ children }: { children: ReactNode }) {
    const shared = useSharedData();
    const branchId = shared.activeBranch?.id ?? null;
    const query = useActiveContextQuery(branchId);
    const queryClient = useQueryClient();

    // Si el bootstrap ya tiene branch pero la query falla (ej. 404 BRANCH_NOT_FOUND
    // o BRANCH_FORBIDDEN tras cambio externo), invalidamos para forzar relogin
    // o re-selección — alineado con cómo SpaAppLayout maneja errores de bootstrap.
    useEffect(() => {
        if (query.isError) {
            queryClient.invalidateQueries({ queryKey: ['bootstrap'] });
        }
    }, [query.isError, queryClient]);

    // Solo aceptamos data cuyo `branch.id` coincida con el branchId actual.
    // Si la query trae data del branch previo (entre switch y refetch), la
    // tratamos como "aún no cargado" para evitar pintar UI del vertical viejo
    // con permisos del nuevo (mostraría inventario/mesas que no aplican).
    const value = useMemo(() => {
        if (!query.data) return null;
        if (branchId !== null && query.data.branch.id !== branchId) return null;
        return query.data;
    }, [query.data, branchId]);

    return <BusinessContext.Provider value={value}>{children}</BusinessContext.Provider>;
}
