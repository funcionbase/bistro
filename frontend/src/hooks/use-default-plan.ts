import { apiFetch } from '@/lib/api';
import { useEffect, useState } from 'react';

/**
 * Información del plan default vigente — endpoint público sin auth.
 * Consumido por enrollment para mostrar precio + IVA antes de loguear.
 */
export interface DefaultPlanInfo {
    slug: string;
    name: string;
    description: string | null;
    price: number;
    currency: string;
    billing_cycle: string;
    price_includes_tax: boolean;
    tax_regime: string;
    tax_rate: number;
    features: string[];
}

interface UseDefaultPlanReturn {
    plan: DefaultPlanInfo | null;
    loading: boolean;
    error: string | null;
}

export function useDefaultPlan(): UseDefaultPlanReturn {
    const [plan, setPlan] = useState<DefaultPlanInfo | null>(null);
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;

        apiFetch('/api/v1/billing/plans/default')
            .then(async (res) => {
                const data = await res.json();
                if (cancelled) return;
                if (!res.ok) {
                    setError(data?.error_code ?? 'DEFAULT_PLAN_ERROR');
                    setPlan(null);
                    return;
                }
                setPlan(data?.data ?? null);
            })
            .catch(() => {
                if (!cancelled) {
                    setError('NETWORK_ERROR');
                    setPlan(null);
                }
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, []);

    return { plan, loading, error };
}
