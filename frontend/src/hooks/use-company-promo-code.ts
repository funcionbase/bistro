import { apiFetch } from '@/lib/api';
import { useEffect, useState, useCallback } from 'react';

/**
 * Promo activo actualmente aplicado a la empresa (snapshot inmutable),
 * con las invoices afectadas.
 */
export interface ActiveCompanyPromoCode {
    id: string;
    code: string;
    name: string;
    discount_percent: number;
    months_duration: number;
    starts_at: string;
    ends_at: string;
    status: string;
    applied_via: 'enrollment' | 'github_action' | 'self_service';
    applied_at: string | null;
    invoices: Array<{
        id: string;
        period_from: string;
        period_to: string;
        amount: number;
        discount_amount: number | null;
        status: string;
    }>;
}

/**
 * Preview de inscripción antes de confirmar.
 */
export interface PromoEnrollPreview {
    code: string;
    name: string;
    description: string | null;
    discount_percent: number;
    months_duration: number;
    current_plan_price: number;
    discount_amount: number;
    discounted_price: number;
    monthly_savings: number;
    starts_at_preview: string;
    ends_at_preview: string;
}

interface UseCompanyPromoCodeReturn {
    active: ActiveCompanyPromoCode | null;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    /** Preview self-service (POST /preview). Devuelve null si error 422. */
    preview: (code: string) => Promise<{ data: PromoEnrollPreview | null; errorCode: string | null; message: string | null }>;
    /** Confirma aplicación (POST). Devuelve true si OK. */
    apply: (code: string) => Promise<{ ok: boolean; errorCode: string | null; message: string | null }>;
    /** Cancela el promo activo (DELETE). Devuelve true si OK. */
    cancel: () => Promise<{ ok: boolean; errorCode: string | null; message: string | null }>;
}

export function useCompanyPromoCode(enabled: boolean = true): UseCompanyPromoCodeReturn {
    const [active, setActive] = useState<ActiveCompanyPromoCode | null>(null);
    const [loading, setLoading] = useState<boolean>(enabled);
    const [error, setError] = useState<string | null>(null);

    const refresh = useCallback(async () => {
        if (!enabled) {
            return;
        }
        setLoading(true);
        setError(null);
        try {
            const res = await apiFetch('/api/v1/company/billing/promo-code');
            const data = await res.json();
            if (!res.ok) {
                setError(data?.message ?? 'Error al cargar el código promocional.');
                return;
            }
            setActive((data?.data ?? null) as ActiveCompanyPromoCode | null);
        } catch {
            setError('Error de red al cargar el código promocional.');
        } finally {
            setLoading(false);
        }
    }, [enabled]);

    useEffect(() => {
        refresh();
    }, [refresh]);

    const preview = useCallback(async (code: string) => {
        const res = await apiFetch('/api/v1/company/billing/promo-code/preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code }),
        });
        const data = await res.json();
        if (!res.ok) {
            return { data: null, errorCode: data?.error_code ?? 'UNKNOWN', message: data?.message ?? null };
        }
        return { data: (data?.data ?? null) as PromoEnrollPreview | null, errorCode: null, message: null };
    }, []);

    const apply = useCallback(
        async (code: string) => {
            const res = await apiFetch('/api/v1/company/billing/promo-code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code }),
            });
            const data = await res.json();
            if (!res.ok) {
                return { ok: false, errorCode: data?.error_code ?? 'UNKNOWN', message: data?.message ?? null };
            }
            await refresh();
            return { ok: true, errorCode: null, message: null };
        },
        [refresh],
    );

    const cancel = useCallback(async () => {
        const res = await apiFetch('/api/v1/company/billing/promo-code', { method: 'DELETE' });
        const data = await res.json();
        if (!res.ok) {
            return { ok: false, errorCode: data?.error_code ?? 'UNKNOWN', message: data?.message ?? null };
        }
        await refresh();
        return { ok: true, errorCode: null, message: null };
    }, [refresh]);

    return { active, loading, error, refresh, preview, apply, cancel };
}
