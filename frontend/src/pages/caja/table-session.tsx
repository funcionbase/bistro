// ponytail: redirect stub — absorbida por /orders/:id (#unificacion-ordenes)
import { apiFetch } from '@/lib/api';
import { useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';

export default function CajaTableSessionRedirect() {
    const sessionId = window.location.pathname.split('/').pop() ?? '';
    const [orderId, setOrderId] = useState<string | null>(null);

    useEffect(() => {
        apiFetch(`/api/v1/caja/table-sessions/${sessionId}`)
            .then((r) => (r.ok ? r.json() : null))
            .then((j: { data?: { order?: { id?: string } } } | null) => {
                const id = j?.data?.order?.id;
                if (id) setOrderId(id);
            })
            .catch(() => undefined);
    }, [sessionId]);

    if (orderId) return <Navigate to={`/orders/${orderId}`} replace />;
    return <div className="h-10 animate-pulse rounded-md bg-muted" />;
}
