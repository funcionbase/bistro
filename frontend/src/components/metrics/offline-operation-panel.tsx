/**
 * Panel de operación offline en /company/metrics (#140).
 *
 * Consume `GET /api/v1/metrics/offline/operation` y muestra:
 *  - 4 KPIs: órdenes sincronizadas, cobros sincronizados, monto total, fallos.
 *
 * Si la empresa nunca operó offline, devuelve null (empty-state silencioso).
 */
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { StatTile } from '@/components/ui/stat-tile';
import { apiFetch } from '@/lib/api';
import { useEffect, useState } from 'react';

interface OfflineMetrics {
    period: { from: string; to: string; label: string };
    totals: {
        orders_synced: number;
        receipts_synced: number;
        failed: number;
        amount_synced: number;
    };
    daily: Array<{ day: string; event_type: string; total_count: number; total_amount: string }>;
}

export function OfflineOperationPanel({
    period,
    formatCurrency,
    enabled,
}: {
    period: string;
    formatCurrency: (n: number) => string;
    enabled: boolean;
}) {
    const [data, setData] = useState<OfflineMetrics | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!enabled) return;
        let cancelled = false;
        const load = async () => {
            setLoading(true);
            try {
                const res = await apiFetch(`/api/v1/metrics/offline/operation?period=${encodeURIComponent(period)}`);
                if (!res.ok) return;
                const json = (await res.json()) as OfflineMetrics;
                if (!cancelled) setData(json);
            } finally {
                if (!cancelled) setLoading(false);
            }
        };
        void load();
        return () => {
            cancelled = true;
        };
    }, [period, enabled]);

    if (!enabled) return null;
    if (!loading && data && data.totals.orders_synced === 0 && data.totals.receipts_synced === 0 && data.totals.failed === 0) {
        return null;
    }

    const t = data?.totals ?? { orders_synced: 0, receipts_synced: 0, failed: 0, amount_synced: 0 };

    return (
        <DashboardPanel title="Operación offline">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile value={t.orders_synced} label="Órdenes sincronizadas" />
                <StatTile value={t.receipts_synced} label="Cobros sincronizados" />
                <StatTile value={formatCurrency(t.amount_synced)} label="Monto cobrado offline" />
                <StatTile value={t.failed} label="Fallos de sync" tone={t.failed > 0 ? 'critical' : 'default'} />
            </div>
        </DashboardPanel>
    );
}

export default OfflineOperationPanel;
