/**
 * Panel de escaneos del menú QR en /company/metrics (#294).
 *
 * Consume `GET /api/v1/metrics/menu-scans` (rollup diario + día en curso):
 *  - 2 KPIs: total escaneos, sesiones únicas (suma de únicos diarios).
 *  - Serie temporal escaneos/día (barras).
 *  - Desglose por mesa (top 10) y por sede (solo vista consolidada).
 */
import { Alert, AlertDescription } from '@/components/ui/alert';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Skeleton } from '@/components/ui/skeleton';
import { StatTile } from '@/components/ui/stat-tile';
import { apiFetch } from '@/lib/api';
import { QrCode } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface DailyScan {
    date: string;
    total_scans: number;
    unique_sessions: number;
}

interface MenuScansData {
    total_scans: number;
    unique_sessions: number;
    daily: DailyScan[];
    by_table: Array<{ table_number: string; total_scans: number }>;
    by_branch: Array<{ branch_id: string; branch_name: string; total_scans: number }>;
}

interface MenuScansResponse {
    period: { from: string; to: string; label: string };
    data: MenuScansData;
}

function DailyTooltip({ active, payload }: { active?: boolean; payload?: Array<{ payload: DailyScan }> }) {
    if (!active || !payload?.length) return null;
    const { date, total_scans, unique_sessions } = payload[0].payload;
    return (
        <div className="bg-card rounded-lg border px-3 py-2 shadow-md">
            <p className="text-sm font-semibold">{date}</p>
            <p className="text-xs">{total_scans.toLocaleString('es-CO')} escaneos</p>
            <p className="text-muted-foreground text-xs">{unique_sessions.toLocaleString('es-CO')} sesiones únicas</p>
        </div>
    );
}

export function MenuScansPanel({
    period,
    dateFrom,
    dateTo,
    branchFilter = 'active',
    enabled,
}: {
    period: string;
    dateFrom: string;
    dateTo: string;
    branchFilter?: string;
    enabled: boolean;
}) {
    const [data, setData] = useState<MenuScansData | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!enabled) return;
        let cancelled = false;
        const load = async () => {
            setLoading(true);
            setError(null);
            try {
                const params = new URLSearchParams({ period });
                if (period === 'custom' && dateFrom && dateTo) {
                    params.set('date_from', dateFrom);
                    params.set('date_to', dateTo);
                }
                if (branchFilter !== 'active') params.set('branch', branchFilter);
                const res = await apiFetch(`/api/v1/metrics/menu-scans?${params.toString()}`);
                const json = await res.json().catch(() => ({}));
                if (cancelled) return;
                if (!res.ok) {
                    setError((json as { message?: string }).message ?? 'No se pudieron cargar los escaneos del menú.');
                    return;
                }
                setData((json as MenuScansResponse).data);
            } catch {
                if (!cancelled) setError('Error de conexión.');
            } finally {
                if (!cancelled) setLoading(false);
            }
        };
        void load();
        return () => {
            cancelled = true;
        };
    }, [period, dateFrom, dateTo, branchFilter, enabled]);

    if (!enabled) return null;

    return (
        <DashboardPanel title="Escaneos del menú QR" icon={QrCode}>
            {error ? (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            ) : loading || !data ? (
                <div className="space-y-3">
                    <Skeleton className="h-20 w-full" />
                    <Skeleton className="h-40 w-full" />
                </div>
            ) : data.total_scans === 0 ? (
                <p className="text-muted-foreground py-6 text-center text-sm">Sin escaneos del menú en el período.</p>
            ) : (
                <div className="space-y-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <StatTile value={data.total_scans.toLocaleString('es-CO')} label="Escaneos" tone="primary" />
                        <StatTile value={data.unique_sessions.toLocaleString('es-CO')} label="Sesiones únicas" />
                    </div>

                    {data.daily.length > 1 && (
                        <ResponsiveContainer width="100%" height={180}>
                            <BarChart data={data.daily} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
                                <XAxis
                                    dataKey="date"
                                    tickFormatter={(d: string) => d.slice(5)}
                                    style={{ fontSize: 11 }}
                                    tickLine={false}
                                    axisLine={false}
                                />
                                <YAxis allowDecimals={false} width={32} style={{ fontSize: 11 }} tickLine={false} axisLine={false} />
                                <Tooltip content={<DailyTooltip />} cursor={{ fill: 'var(--color-body)' }} />
                                <Bar dataKey="total_scans" fill="var(--color-accent-blue)" radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        {data.by_table.length > 0 && (
                            <div className="space-y-1">
                                <div className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Por mesa</div>
                                <ul className="divide-border divide-y">
                                    {data.by_table.map((row) => (
                                        <li key={row.table_number} className="flex items-center justify-between py-1.5 text-sm">
                                            <span className="text-foreground truncate">Mesa {row.table_number}</span>
                                            <span className="text-muted-foreground tabular-nums">{row.total_scans.toLocaleString('es-CO')}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {data.by_branch.length > 0 && (
                            <div className="space-y-1">
                                <div className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Por sede</div>
                                <ul className="divide-border divide-y">
                                    {data.by_branch.map((row) => (
                                        <li key={row.branch_id} className="flex items-center justify-between py-1.5 text-sm">
                                            <span className="text-foreground truncate">{row.branch_name}</span>
                                            <span className="text-muted-foreground tabular-nums">{row.total_scans.toLocaleString('es-CO')}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </DashboardPanel>
    );
}

export default MenuScansPanel;
