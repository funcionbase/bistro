import { apiFetch } from '@/lib/api';
import type { MenuEngineeringMatrix, MetricPeriod } from '@/types';
import { useCallback } from 'react';

/**
 * Lectura del matrix de menu engineering (issue #114).
 *
 * Período por defecto = month (mediana estable). El backend cachea ~5 min, así
 * que múltiples llamadas seguidas no pegan a la BD.
 */
export function useMenuEngineering() {
    const fetchMatrix = useCallback(async (period: MetricPeriod, dateFrom: string, dateTo: string): Promise<MenuEngineeringMatrix> => {
        const params = new URLSearchParams({ period });
        if (period === 'custom' && dateFrom && dateTo) {
            params.set('date_from', dateFrom);
            params.set('date_to', dateTo);
        }
        const res = await apiFetch(`/api/v1/metrics/menu-engineering?${params.toString()}`);
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as MenuEngineeringMatrix;
    }, []);

    return { fetchMatrix };
}
