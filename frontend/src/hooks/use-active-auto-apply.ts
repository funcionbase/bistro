import { apiFetch } from '@/lib/api';
import type { ActiveAutoApply } from '@/types/coupon';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Sondea `/cart/active-auto-apply` para anunciar cupones programados (happy hour)
 * que se aplicarán automáticamente al cerrar la orden.
 *
 * No persiste nada. Solo es indicativo para el operador: cuando el total cambie
 * o la franja se cierre, el backend volverá a evaluar al crear la orden.
 *
 * Refresca cada 60s y cuando cambia el total. No dispara cuando total === 0.
 */
export function useActiveAutoApply(orderTotal: number, clientPhone?: string): ActiveAutoApply | null {
    const [info, setInfo] = useState<ActiveAutoApply | null>(null);
    const mounted = useRef(true);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);

    const check = useCallback(async () => {
        if (orderTotal <= 0) {
            setInfo(null);
            return;
        }
        try {
            const res = await apiFetch('/api/v1/cart/active-auto-apply', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_total: orderTotal, client_phone: clientPhone ?? null }),
            });
            if (!res.ok) {
                if (mounted.current) setInfo(null);
                return;
            }
            const json = (await res.json()) as ActiveAutoApply;
            if (mounted.current) setInfo(json.active ? json : null);
        } catch {
            if (mounted.current) setInfo(null);
        }
    }, [orderTotal, clientPhone]);

    useEffect(() => {
        void check();
        const id = window.setInterval(() => {
            if (document.hidden) return; // pestaña oculta: no gastar backend
            void check();
        }, 60_000);
        return () => window.clearInterval(id);
    }, [check]);

    return info;
}
