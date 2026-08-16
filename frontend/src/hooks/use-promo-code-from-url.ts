import { apiFetch } from '@/lib/api';
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

/**
 * Preview pública de un promo code consumida desde la URL `?promo=...`
 * en `/enroll` (sin auth). Devuelve los datos para mostrar el panel hero
 * dedicado al descuento.
 */
export interface PromoCodePreview {
    code: string;
    name: string;
    description: string | null;
    discount_percent: number;
    months_duration: number;
    plan_default_price: number;
    discount_amount: number;
    discounted_price: number;
    monthly_savings: number;
}

interface UsePromoCodeFromUrlReturn {
    promoSlug: string | null;
    preview: PromoCodePreview | null;
    loading: boolean;
    invalidReason: string | null;
}

/**
 * Lee `?promo=SLUG` del URL y consulta el preview público. Si el slug es
 * inválido (NOT_FOUND, EXPIRED, MAX_COMPANIES_REACHED, etc.) devuelve
 * `invalidReason` con el código del error — el caller muestra un Alert
 * pero permite continuar el enrollment con plan default.
 */
export function usePromoCodeFromUrl(): UsePromoCodeFromUrlReturn {
    const [searchParams] = useSearchParams();
    const rawSlug = searchParams.get('promo');
    const promoSlug = rawSlug !== null && rawSlug.trim() !== '' ? rawSlug.trim().toUpperCase() : null;

    const [preview, setPreview] = useState<PromoCodePreview | null>(null);
    const [loading, setLoading] = useState<boolean>(false);
    const [invalidReason, setInvalidReason] = useState<string | null>(null);

    useEffect(() => {
        if (promoSlug === null) {
            setPreview(null);
            setInvalidReason(null);
            return;
        }

        let cancelled = false;
        setLoading(true);
        setInvalidReason(null);

        apiFetch(`/api/v1/promo-codes/${encodeURIComponent(promoSlug)}/preview`)
            .then(async (res) => {
                const data = await res.json();
                if (cancelled) return;
                if (!res.ok) {
                    setInvalidReason(data?.error_code ?? 'PROMO_CODE_INVALID');
                    setPreview(null);
                    return;
                }
                setPreview(data?.data ?? null);
            })
            .catch(() => {
                if (!cancelled) {
                    setInvalidReason('NETWORK_ERROR');
                    setPreview(null);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [promoSlug]);

    return { promoSlug, preview, loading, invalidReason };
}
