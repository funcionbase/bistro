import { apiFetch } from '@/lib/api';
import type { DeliveryMetric } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

export type MetricPeriodOption = 'today' | 'week' | 'month';

interface UseDeliveryMetricsReturn {
    metrics: DeliveryMetric[];
    loading: boolean;
    period: MetricPeriodOption;
    changePeriod: (p: MetricPeriodOption) => void;
    fetchMetrics: (period?: MetricPeriodOption) => Promise<void>;
}

export function useDeliveryMetrics(token: string | null, branchFilter: string = 'active'): UseDeliveryMetricsReturn {
    const [metrics, setMetrics] = useState<DeliveryMetric[]>([]);
    const [loading, setLoading] = useState(false);
    const [period, setPeriod] = useState<MetricPeriodOption>('today');
    const isMounted = useRef(true);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
        };
    }, []);

    const fetchMetrics = useCallback(
        async (requestedPeriod?: MetricPeriodOption): Promise<void> => {
            const p = requestedPeriod ?? period;
            if (!token) return;
            setLoading(true);
            try {
                const branchParam = branchFilter !== 'active' ? `&branch=${branchFilter}` : '';
                const res = await apiFetch(`/api/v1/deliveries/metrics?period=${p}${branchParam}`);
                const data = await res.json();
                if (!isMounted.current) return;
                if (res.ok) {
                    setMetrics(data.data ?? []);
                }
            } catch {
                // silently ignore — metrics are non-critical
            } finally {
                if (isMounted.current) setLoading(false);
            }
        },
        [token, period, branchFilter],
    );

    const changePeriod = useCallback(
        (newPeriod: MetricPeriodOption) => {
            setPeriod(newPeriod);
            void fetchMetrics(newPeriod);
        },
        [fetchMetrics],
    );

    return { metrics, loading, period, changePeriod, fetchMetrics };
}
