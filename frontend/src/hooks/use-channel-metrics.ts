import { apiFetch } from '@/lib/api';
import { useEffect, useState } from 'react';

export interface ChannelMetrics {
    messages_per_day: { date: string; count: number }[];
    /** Tiempo medio de respuesta en segundos, o null si no hubo respuestas. */
    avg_response_seconds: number | null;
}

/**
 * Métricas del canal para la tarjeta (§8.4b punto 11).
 *
 * Fetch único al montar, solo cuando `enabled` (canal conectado): sin polling
 * —la tarjeta es de configuración, no de operación— y sin pedir métricas de un
 * número que todavía no manda nada.
 */
export function useChannelMetrics(channelId: string, enabled: boolean): { metrics: ChannelMetrics | null; loading: boolean } {
    const [metrics, setMetrics] = useState<ChannelMetrics | null>(null);
    const [loading, setLoading] = useState(enabled);

    useEffect(() => {
        if (!enabled) {
            setLoading(false);
            return;
        }
        let cancelled = false;
        setLoading(true);
        (async () => {
            try {
                const res = await apiFetch(`/api/v1/whatsapp/channels/${channelId}/metrics`);
                if (!res.ok || cancelled) return;
                const json = await res.json();
                if (!cancelled) setMetrics((json as { data: ChannelMetrics }).data);
            } catch {
                // Silencioso: la tarjeta funciona sin las métricas.
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [channelId, enabled]);

    return { metrics, loading };
}

/** Segundos → texto corto y humano para la tarjeta ("~4 min", "~2 h"). */
export function formatResponseTime(seconds: number | null): string | null {
    if (seconds === null || seconds < 0) return null;
    if (seconds < 60) return `~${Math.round(seconds)} s`;
    if (seconds < 3600) return `~${Math.round(seconds / 60)} min`;
    return `~${Math.round((seconds / 3600) * 10) / 10} h`;
}
