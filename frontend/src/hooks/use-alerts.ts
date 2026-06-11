import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';

export type AlertSeverity = 'info' | 'warning' | 'critical';
export type AlertType = 'margin_below' | 'cost_increase' | 'item_low_volume' | 'low_stock';
export type AlertStatus = 'active' | 'dismissed' | 'actioned';

export interface AlertEvent {
    id: string;
    rule_id: string;
    type: AlertType;
    severity: AlertSeverity;
    target_type: 'menu_item' | 'ingredient' | 'global';
    target_id: string | null;
    payload: Record<string, unknown>;
    status: AlertStatus;
    triggered_at: string | null;
    dismissed_at: string | null;
    actioned_at: string | null;
    actioned_note: string | null;
    actioned_by: string | null;
}

export interface AlertRule {
    id: string;
    type: AlertType;
    threshold: number;
    period_days: number;
    enabled: boolean;
    notify_dashboard: boolean;
    notify_whatsapp: boolean;
}

export interface AlertSummary {
    active_total: number;
    by_severity: Record<AlertSeverity, number>;
}

interface UseAlertsReturn {
    alerts: AlertEvent[];
    summary: AlertSummary | null;
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    dismiss: (id: string) => Promise<void>;
    action: (id: string, note?: string) => Promise<void>;
}

export function useAlerts(status: AlertStatus | 'all' = 'active', pollMs = 5 * 60 * 1000): UseAlertsReturn {
    const [alerts, setAlerts] = useState<AlertEvent[]>([]);
    const [summary, setSummary] = useState<AlertSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const mounted = useRef(true);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);

    const fetchAll = useCallback(async (): Promise<void> => {
        try {
            setLoading(true);
            const [listRes, summaryRes] = await Promise.all([
                apiFetch(`/api/v1/alerts?status=${status}&per_page=50`),
                apiFetch('/api/v1/alerts/summary'),
            ]);
            if (!mounted.current) return;

            if (!listRes.ok) {
                const json = (await listRes.json().catch(() => ({}))) as { message?: string };
                setError(json.message ?? 'Error al cargar alertas.');
                setLoading(false);
                return;
            }

            const listJson = (await listRes.json()) as { data: AlertEvent[] };
            setAlerts(listJson.data);

            if (summaryRes.ok) {
                const summaryJson = (await summaryRes.json()) as { data: AlertSummary };
                setSummary(summaryJson.data);
            }
            setError(null);
        } catch {
            if (mounted.current) setError('Error de conexión.');
        } finally {
            if (mounted.current) setLoading(false);
        }
    }, [status]);

    useEffect(() => {
        void fetchAll();
        if (pollMs <= 0) return;
        const id = window.setInterval(() => {
            void fetchAll();
        }, pollMs);
        return () => window.clearInterval(id);
    }, [fetchAll, pollMs]);

    const dismiss = useCallback(
        async (id: string): Promise<void> => {
            const res = await apiFetch(`/api/v1/alerts/${id}/dismiss`, { method: 'POST' });
            if (!res.ok) {
                const json = (await res.json().catch(() => ({}))) as { message?: string };
                throw new Error(json.message ?? 'No se pudo descartar.');
            }
            await fetchAll();
        },
        [fetchAll],
    );

    const action = useCallback(
        async (id: string, note?: string): Promise<void> => {
            const res = await apiFetch(`/api/v1/alerts/${id}/action`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ note: note ?? null }),
            });
            if (!res.ok) {
                const json = (await res.json().catch(() => ({}))) as { message?: string };
                throw new Error(json.message ?? 'No se pudo marcar como revisado.');
            }
            await fetchAll();
        },
        [fetchAll],
    );

    return { alerts, summary, loading, error, refresh: fetchAll, dismiss, action };
}

interface UseAlertRulesReturn {
    rules: AlertRule[];
    loading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    update: (type: AlertType, data: Partial<AlertRule>) => Promise<void>;
}

export function useAlertRules(): UseAlertRulesReturn {
    const [rules, setRules] = useState<AlertRule[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const mounted = useRef(true);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);

    const refresh = useCallback(async (): Promise<void> => {
        try {
            setLoading(true);
            const res = await apiFetch('/api/v1/alert-rules');
            if (!mounted.current) return;
            if (!res.ok) {
                const json = (await res.json().catch(() => ({}))) as { message?: string };
                setError(json.message ?? 'Error al cargar reglas.');
                setLoading(false);
                return;
            }
            const json = (await res.json()) as { data: AlertRule[] };
            setRules(json.data);
            setError(null);
        } catch {
            if (mounted.current) setError('Error de conexión.');
        } finally {
            if (mounted.current) setLoading(false);
        }
    }, []);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    const update = useCallback(
        async (type: AlertType, data: Partial<AlertRule>): Promise<void> => {
            const res = await apiFetch(`/api/v1/alert-rules/${type}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (!res.ok) {
                const json = (await res.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };
                throw new Error(json.errors ? Object.values(json.errors).flat().join(' ') : (json.message ?? 'Error al guardar.'));
            }
            await refresh();
        },
        [refresh],
    );

    return { rules, loading, error, refresh, update };
}
