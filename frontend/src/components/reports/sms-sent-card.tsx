import { Alert, AlertDescription } from '@/components/ui/alert';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { KpiCell } from '@/components/ui/kpi-cell';
import { Skeleton } from '@/components/ui/skeleton';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { MessageSquare } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Seguimiento de SMS enviados al cliente por cambios de estado de orden.
 * Las fechas y la sede se controlan desde el filtro principal de la página.
 */

interface BranchCount {
    branch_id: string;
    branch_name: string;
    total: number;
}

interface SmsCountsResponse {
    period: { from: string; to: string; label: string };
    data: { total: number; by_branch: BranchCount[] };
}

export default function SmsSentCard({
    branchFilter = 'active',
    dateFrom,
    dateTo,
}: {
    branchFilter?: string;
    dateFrom: string;
    dateTo: string;
}) {
    const token = useToken();
    const [data, setData] = useState<SmsCountsResponse['data'] | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fetchData = useCallback(async () => {
        if (!token || !dateFrom || !dateTo) return;
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams({ period: 'custom', date_from: dateFrom, date_to: dateTo });
            if (branchFilter !== 'active') params.set('branch', branchFilter);
            const res = await apiFetch(`/api/v1/metrics/sms/counts?${params.toString()}`);
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError((json as { message?: string }).message ?? 'No se pudieron cargar los SMS enviados.');
                return;
            }
            setData((json as SmsCountsResponse).data);
        } catch {
            setError('Error de conexión.');
        } finally {
            setLoading(false);
        }
    }, [token, dateFrom, dateTo, branchFilter]);

    useEffect(() => {
        void fetchData();
    }, [fetchData]);

    return (
        <DashboardPanel title="SMS enviados" icon={MessageSquare}>
            {error ? (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            ) : loading ? (
                <div className="space-y-3">
                    <Skeleton className="h-20 w-full" />
                    <Skeleton className="h-12 w-full" />
                </div>
            ) : (
                <div className="space-y-4">
                    <KpiCell label="Total enviados" value={(data?.total ?? 0).toLocaleString('es-CO')} />

                    {data && data.by_branch.length > 0 ? (
                        <div className="space-y-1">
                            <div className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Por sede</div>
                            <ul className="divide-border divide-y">
                                {data.by_branch.map((row) => (
                                    <li key={row.branch_id} className="flex items-center justify-between py-1.5 text-sm">
                                        <span className="text-foreground truncate">{row.branch_name}</span>
                                        <span className="text-muted-foreground tabular-nums">{row.total.toLocaleString('es-CO')}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">Sin SMS enviados en el período.</p>
                    )}
                </div>
            )}
        </DashboardPanel>
    );
}
