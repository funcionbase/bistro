import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface LoyaltyTierProgress {
    tier: string;
    multiplier: number;
    next_tier: string | null;
    points_to_next: number | null;
    progress_pct: number;
}

export interface LoyaltyMovement {
    id: string;
    type: 'earn' | 'redeem' | 'refund_reverse' | 'adjust' | 'expire';
    points: number;
    reference_type: string | null;
    reference_id: string | null;
    actor_id: string | null;
    actor_name?: string | null;
    meta: Record<string, unknown> | null;
    created_at: string;
}

export interface LoyaltyReward {
    points: number;
    label: string;
    discount_type: 'fixed_amount' | 'percentage';
    discount_value: number;
    min_order_amount: number;
}

export interface LoyaltyAccountFull {
    id: string | null;
    company_nit: string;
    client_phone: string;
    balance: number;
    lifetime_earned: number;
    tier: string;
    tier_progress: LoyaltyTierProgress;
    last_activity_at: string | null;
    created_at: string | null;
    movements: LoyaltyMovement[];
    rewards: Record<string, LoyaltyReward>;
    config: { enabled: boolean };
}

interface UseLoyaltyReturn {
    account: LoyaltyAccountFull | null;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    adjust: (points: number, reason: string) => Promise<void>;
    redeem: (rewardKey: string) => Promise<{ coupon_code: string }>;
}

export function useLoyalty(token: string | null, phone: string): UseLoyaltyReturn {
    const [account, setAccount] = useState<LoyaltyAccountFull | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const mounted = useRef(true);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);

    const fetchAccount = useCallback(async (): Promise<void> => {
        if (!token || !phone) return;
        try {
            setLoading(true);
            const res = await apiFetch(`/api/v1/loyalty/accounts/${encodeURIComponent(phone)}`);
            if (!mounted.current) return;
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setError((json as { message?: string }).message ?? 'Error al cargar fidelización.');
                setLoading(false);
                return;
            }
            const json = (await res.json()) as { data: LoyaltyAccountFull };
            setAccount(json.data);
            setError(null);
        } catch {
            if (mounted.current) setError('Error de conexión.');
        } finally {
            if (mounted.current) setLoading(false);
        }
    }, [token, phone]);

    useEffect(() => {
        void fetchAccount();
    }, [fetchAccount]);

    const adjust = useCallback(
        async (points: number, reason: string): Promise<void> => {
            const res = await apiFetch(`/api/v1/loyalty/accounts/${encodeURIComponent(phone)}/adjust`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ points, reason }),
            });
            if (!res.ok) {
                const json = (await res.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };
                throw new Error(json.errors ? Object.values(json.errors).flat().join(' ') : (json.message ?? 'Error al ajustar puntos.'));
            }
            await fetchAccount();
        },
        [phone, fetchAccount],
    );

    const redeem = useCallback(
        async (rewardKey: string): Promise<{ coupon_code: string }> => {
            const res = await apiFetch(`/api/v1/loyalty/accounts/${encodeURIComponent(phone)}/redeem`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reward_key: rewardKey }),
            });
            if (!res.ok) {
                const json = (await res.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };
                throw new Error(json.errors ? Object.values(json.errors).flat().join(' ') : (json.message ?? 'Error al canjear.'));
            }
            const json = (await res.json()) as { data: { coupon: { code: string } } };
            await fetchAccount();
            return { coupon_code: json.data.coupon.code };
        },
        [phone, fetchAccount],
    );

    return { account, loading, error, refresh: fetchAccount, adjust, redeem };
}
