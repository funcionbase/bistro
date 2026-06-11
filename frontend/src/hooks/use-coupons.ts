import { apiFetch } from '@/lib/api';
import type { Coupon, CouponFormData, CouponRedemption, CouponStatus, PaginatedResponse } from '@/types/coupon';
import { useCallback, useEffect, useRef, useState } from 'react';

interface UseCouponsReturn {
    coupons: Coupon[];
    loading: boolean;
    error: string | null;
    fetchCoupons: () => Promise<void>;
    createCoupon: (data: Partial<CouponFormData>) => Promise<Coupon>;
    updateCoupon: (id: string, data: Partial<CouponFormData>) => Promise<Coupon>;
    updateCouponStatus: (id: string, status: CouponStatus) => Promise<void>;
    deleteCoupon: (id: string) => Promise<void>;
    fetchCouponRedemptions: (couponId: string, page?: number) => Promise<PaginatedResponse<CouponRedemption>>;
}

export function useCoupons(token: string | null): UseCouponsReturn {
    const [coupons, setCoupons] = useState<Coupon[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const isMounted = useRef(true);

    const fetchCoupons = useCallback(async () => {
        if (!token) return;
        try {
            const res = await apiFetch('/api/v1/coupons');
            const data = await res.json();
            if (!isMounted.current) return;
            if (!res.ok) {
                setError(data.message ?? 'Error al cargar cupones.');
                return;
            }
            setCoupons(data.data ?? []);
            setError(null);
        } catch {
            if (isMounted.current) setError('Error de conexión.');
        } finally {
            if (isMounted.current) setLoading(false);
        }
    }, [token]);

    useEffect(() => {
        isMounted.current = true;
        fetchCoupons();
        return () => {
            isMounted.current = false;
        };
    }, [fetchCoupons]);

    const createCoupon = useCallback(async (data: Partial<CouponFormData>): Promise<Coupon> => {
        const res = await apiFetch('/api/v1/coupons', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data;
    }, []);

    const updateCoupon = useCallback(async (id: string, data: Partial<CouponFormData>): Promise<Coupon> => {
        const res = await apiFetch(`/api/v1/coupons/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        const json = await res.json();
        if (!res.ok) throw json;
        return json.data;
    }, []);

    const updateCouponStatus = useCallback(async (id: string, status: CouponStatus): Promise<void> => {
        const res = await apiFetch(`/api/v1/coupons/${id}/status`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status }),
        });
        if (!res.ok) {
            const json = await res.json();
            throw json;
        }
    }, []);

    const deleteCoupon = useCallback(async (id: string): Promise<void> => {
        const res = await apiFetch(`/api/v1/coupons/${id}`, { method: 'DELETE' });
        if (!res.ok) {
            const json = await res.json();
            throw json;
        }
    }, []);

    const fetchCouponRedemptions = useCallback(async (couponId: string, page: number = 1): Promise<PaginatedResponse<CouponRedemption>> => {
        const res = await apiFetch(`/api/v1/coupons/${couponId}/redemptions?page=${page}`);
        const json = await res.json();
        if (!res.ok) throw json;
        return json;
    }, []);

    return { coupons, loading, error, fetchCoupons, createCoupon, updateCoupon, updateCouponStatus, deleteCoupon, fetchCouponRedemptions };
}
