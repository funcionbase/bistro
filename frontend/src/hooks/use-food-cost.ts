import { apiFetch } from '@/lib/api';
import type { FoodCostHistory, FoodCostSummary, MetricPeriod } from '@/types';
import { useCallback } from 'react';

/**
 * Lectura del food cost (costo de alimentos) en tiempo real.
 *
 * El backend dispara lazy backfill del snapshot diario al primer hit del día,
 * así que este hook NO necesita preocuparse por activar el cron — basta con
 * llamar fetchSummary() y la primera respuesta del día puede ser ~1-2s más
 * lenta porque genera el histórico.
 */
function buildQuery(period: MetricPeriod, dateFrom: string, dateTo: string): string {
    const params = new URLSearchParams({ period });
    if (period === 'custom' && dateFrom && dateTo) {
        params.set('date_from', dateFrom);
        params.set('date_to', dateTo);
    }
    return params.toString();
}

export function useFoodCost() {
    const fetchSummary = useCallback(async (period: MetricPeriod, dateFrom: string, dateTo: string): Promise<FoodCostSummary> => {
        const res = await apiFetch(`/api/v1/metrics/foodcost/summary?${buildQuery(period, dateFrom, dateTo)}`);
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data as FoodCostSummary;
    }, []);

    const fetchItemHistory = useCallback(
        async (menuItemId: string, period: MetricPeriod, dateFrom: string, dateTo: string): Promise<FoodCostHistory> => {
            const res = await apiFetch(
                `/api/v1/metrics/foodcost/items/${encodeURIComponent(menuItemId)}/history?${buildQuery(period, dateFrom, dateTo)}`,
            );
            const json = await res.json();
            if (!res.ok) throw json;
            return json.data as FoodCostHistory;
        },
        [],
    );

    return { fetchSummary, fetchItemHistory };
}
