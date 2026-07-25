import { apiFetch } from '@/lib/api';
import type { CouponValidationResponse } from '@/types/coupon';
import { useCallback, useRef, useState } from 'react';

interface UseCouponValidationReturn {
    validating: boolean;
    error: string | null;
    appliedCoupon: CouponValidationResponse | null;
    validateCoupon: (code: string, total: number, phone?: string) => Promise<void>;
    removeCoupon: () => void;
}

export function useCouponValidation(): UseCouponValidationReturn {
    const [validating, setValidating] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [appliedCoupon, setAppliedCoupon] = useState<CouponValidationResponse | null>(null);
    // Token de secuencia: cada validación incrementa el contador; una respuesta
    // cuyo token ya no es el vigente es de una llamada previa (el usuario tipeó
    // otro código) y se descarta para no pisar el estado con datos viejos.
    const seqRef = useRef(0);

    const validateCoupon = useCallback(async (code: string, total: number, phone?: string) => {
        const seq = ++seqRef.current;
        setValidating(true);
        setError(null);

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 5000);

        try {
            const res = await apiFetch('/api/v1/cart/apply-coupon', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ coupon_code: code, order_total: total, client_phone: phone }),
                signal: controller.signal,
            });

            clearTimeout(timeoutId);
            // Respuesta vieja: llegó tarde, ya hay otra validación en curso.
            if (seq !== seqRef.current) return;

            if (!res.ok) {
                setAppliedCoupon(null);
                setError('No se pudo validar el cupón. Intenta de nuevo.');
                return;
            }

            const json: CouponValidationResponse = await res.json();
            if (seq !== seqRef.current) return;

            if (json.valid) {
                setAppliedCoupon(json);
                setError(null);
            } else {
                setAppliedCoupon(null);
                setError(json.error ?? 'Cupón inválido');
            }
        } catch (e) {
            clearTimeout(timeoutId);
            if (seq !== seqRef.current) return;
            if (e instanceof Error && e.name === 'AbortError') {
                setError('La solicitud tardó demasiado. Intenta de nuevo.');
            } else {
                setError('Error de conexión.');
            }
        } finally {
            if (seq === seqRef.current) setValidating(false);
        }
    }, []);

    const removeCoupon = useCallback(() => {
        setAppliedCoupon(null);
        setError(null);
    }, []);

    return { validating, error, appliedCoupon, validateCoupon, removeCoupon };
}
