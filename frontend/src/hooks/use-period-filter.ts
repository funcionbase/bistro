import type { Period } from '@/types';
import { useState } from 'react';

interface UsePeriodFilterResult {
    period: Period;
    setPeriod: (period: Period) => void;
    branchFilter: string;
    setBranchFilter: (branch: string) => void;
    isLoading: boolean;
}

/**
 * Estado del filtro de período + sede del dashboard (#220 — agnóstico del
 * transporte).
 *
 * Sólo mantiene el estado; el componente reacciona al cambio refetcheando
 * sus datos (TanStack Query en el shell SPA). Antes disparaba `router.reload`
 * de Inertia para recargar props diferidas — ya no aplica.
 */
export function usePeriodFilter(initialPeriod: Period = 'today'): UsePeriodFilterResult {
    const [period, setPeriod] = useState<Period>(initialPeriod);
    const [branchFilter, setBranchFilter] = useState<string>('active');

    return { period, setPeriod, branchFilter, setBranchFilter, isLoading: false };
}
