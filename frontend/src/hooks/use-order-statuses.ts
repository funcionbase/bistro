import { ORDER_STATUS_FALLBACK } from '@/lib/order-status';
import { useSharedData } from '@/lib/shared-data';
import type { OrderStatusesConfig } from '@/types';

/**
 * Lee la config canónica de estados de orden desde el contexto global de la
 * SPA (cargado vía GET /api/v1/bootstrap). Fallback embebido para cuando aún
 * no hay contexto disponible (tests, hooks aislados, primer render).
 */
export function useOrderStatuses(): OrderStatusesConfig {
    const page = { props: useSharedData() };
    return (page.props.orderStatuses as OrderStatusesConfig | undefined) ?? ORDER_STATUS_FALLBACK;
}
