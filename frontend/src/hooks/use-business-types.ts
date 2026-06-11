import { apiClient } from '@/lib/api-client';
import { useQuery, type UseQueryResult } from '@tanstack/react-query';

/**
 * Vertical disponible en el catálogo (#237). Mantenido en sincronía con
 * `App\Models\BusinessType` y `BusinessContextController::catalog()`.
 */
export interface BusinessTypeOption {
    slug: string;
    label_es: string;
    label_en: string;
    icon_key: string | null;
    default_capabilities: Record<string, boolean>;
    prep_area_defaults: Array<{
        slug: string;
        label: string;
        color?: string;
        icon_key?: string | null;
    }>;
    display_order: number;
}

interface BusinessTypesResponse {
    data: BusinessTypeOption[];
}

export const BUSINESS_TYPES_QUERY_KEY = ['business-types'] as const;

/**
 * Carga el catálogo de verticales. Usado por el wizard de onboarding y por el
 * selector "cambiar tipo de negocio" de la sede.
 */
export function useBusinessTypes(): UseQueryResult<BusinessTypeOption[]> {
    return useQuery<BusinessTypeOption[]>({
        queryKey: BUSINESS_TYPES_QUERY_KEY,
        queryFn: async ({ signal }) => {
            const res = await apiClient.get<BusinessTypesResponse>('/api/v1/business-types', { signal });
            return res.data;
        },
        staleTime: 5 * 60_000,
    });
}
