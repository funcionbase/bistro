import { Alert, AlertDescription } from '@/components/ui/alert';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { KpiCell } from '@/components/ui/kpi-cell';
import { PeriodTabs } from '@/components/ui/period-tabs';
import { Skeleton } from '@/components/ui/skeleton';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { MessageSquare } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Seguimiento de SMS enviados al cliente por cambios de estado de orden (#275).
 *
 * Total de la empresa + desglose por sede en el período. Consume
 * `/api/v1/metrics/sms/counts` (permiso reports.read, consolidación multi-sede
 * vía branch.consolidate — la sede activa la inyecta el backend). Sirve para
 * monitorear el gasto SNS (cobra por segmento y país).
 */

type SmsPeriod = 'today' | 'week' | 'month' | 'custom';

const PERIOD_OPTIONS: ReadonlyArray<{ value: SmsPeriod; label: string }> = [
    { value: 'today', label: 'Hoy' },
    { value: 'week', label: 'Semana' },
    { value: 'month', label: 'Mes' },
    { value: 'custom', label: 'Rango' },
];

interface BranchCount {
    branch_id: string;
    branch_name: string;
    total: number;
}

interface SmsCountsResponse {
    period: { from: string; to: string; label: string };
    data: { total: number; by_branch: BranchCount[] };
}

export default function SmsSentCard() {
    const token = useToken();
    const [period, setPeriod] = useState<SmsPeriod>('today');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [appliedCustom, setAppliedCustom] = useState<{ from: string; to: string } | null>(null);
    const [data, setData] = useState<SmsCountsResponse['data'] | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fetchData = useCallback(async () => {
        if (!token) return;
        if (period === 'custom' && !appliedCustom) return;

        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams({ period });
            if (period === 'custom' && appliedCustom) {
                params.set('date_from', appliedCustom.from);
                params.set('date_to', appliedCustom.to);
            }
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
    }, [token, period, appliedCustom]);

    useEffect(() => {
        void fetchData();
    }, [fetchData]);

    return (
        <DashboardPanel
            title="SMS enviados"
            icon={MessageSquare}
            rightSlot={
                <PeriodTabs<SmsPeriod>
                    options={PERIOD_OPTIONS}
                    value={period}
                    onChange={setPeriod}
                    customValue="custom"
                    dateFrom={dateFrom}
                    dateTo={dateTo}
                    onDateFromChange={setDateFrom}
                    onDateToChange={setDateTo}
                    onApplyCustom={() => dateFrom && dateTo && setAppliedCustom({ from: dateFrom, to: dateTo })}
                    applyDisabled={!dateFrom || !dateTo}
                />
            }
        >
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
