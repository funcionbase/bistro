import { LoyaltyBadge } from '@/components/loyalty-badge';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { KpiCell } from '@/components/ui/kpi-cell';
import { Label } from '@/components/ui/label';
import { LoyaltyReportsSkeleton } from '@/components/ui/loyalty-reports-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { StatTile } from '@/components/ui/stat-tile';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { formatCurrency } from '@/lib/coupon-helpers';
import { cn } from '@/lib/utils';

import { todayInBogota } from '@/lib/datetime';
import { AlertCircle, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface SummaryData {
    enabled: boolean;
    range: { from: string; to: string };
    totals: {
        points_earned: number;
        points_redeemed: number;
        points_expired: number;
        points_reversed: number;
        earn_events: number;
        redeem_events: number;
        active_earners: number;
    };
    top_clients: Array<{
        client_phone: string;
        tier: string;
        balance: number;
        lifetime_earned: number;
        points_earned_period: number;
        points_redeemed_period: number;
    }>;
    redemption_rate: {
        issued: number;
        applied: number;
        expired: number;
        cancelled: number;
        total: number;
        rate: number;
    };
    arpu_by_tier: Array<{ tier: string; clients: number; revenue: string | number; arpu: string | number }>;
    expirations: { events: number; points_expired: number; accounts_expired: number };
    tiers_distribution: Array<{ tier: string; clients: number; total_balance: number }>;
}


function defaultRange(): { from: string; to: string } {
    const today = todayInBogota();
    const past = new Date(new Date(`${today}T00:00:00`).getTime() - 30 * 24 * 3600 * 1000);
    return { from: past.toISOString().slice(0, 10), to: today };
}

export default function LoyaltyReports() {
    const token = useToken();
    const [range, setRange] = useState(defaultRange());
    const [data, setData] = useState<SummaryData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const fetchData = useCallback(async () => {
        if (!token) return;
        setLoading(true);
        try {
            const res = await apiFetch(`/api/v1/loyalty/reports/summary?from=${range.from}&to=${range.to}`);
            if (!res.ok) {
                setError('No se pudo cargar el reporte de fidelización.');
                return;
            }
            const json = (await res.json()) as { data: SummaryData };
            setData(json.data);
            setError(null);
        } catch {
            setError('Error de conexión.');
        } finally {
            setLoading(false);
        }
    }, [token, range.from, range.to]);

    useEffect(() => {
        void fetchData();
    }, [fetchData]);

    return (
        <PageShell title="Reportes de Fidelización">
            <div className="p-4 lg:p-6">
                {loading && !data ? (
                    <LoyaltyReportsSkeleton />
                ) : (
                    <div className="flex flex-col gap-6">
                        <PageHeader
                            eyebrow="Fidelización"
                            title="Reportes"
                            description={`Métricas del programa de puntos. Período ${range.from} → ${range.to}.`}
                            actions={
                                <div className="flex flex-wrap items-end gap-2 sm:flex-nowrap">
                                    <div className="flex-1 space-y-1 sm:flex-none">
                                        <Label htmlFor="loyalty-from" className="text-xs">
                                            Desde
                                        </Label>
                                        <Input
                                            id="loyalty-from"
                                            type="date"
                                            value={range.from}
                                            onChange={(e) => setRange({ ...range, from: e.target.value })}
                                            className="h-9"
                                        />
                                    </div>
                                    <div className="flex-1 space-y-1 sm:flex-none">
                                        <Label htmlFor="loyalty-to" className="text-xs">
                                            Hasta
                                        </Label>
                                        <Input
                                            id="loyalty-to"
                                            type="date"
                                            value={range.to}
                                            onChange={(e) => setRange({ ...range, to: e.target.value })}
                                            className="h-9"
                                        />
                                    </div>
                                    <Button
                                        onClick={fetchData}
                                        disabled={loading}
                                        variant="outline"
                                        size="icon"
                                        title="Actualizar"
                                        aria-label="Actualizar reporte"
                                        className="h-9 w-9 self-end"
                                    >
                                        <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} />
                                    </Button>
                                </div>
                            }
                        />

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {!data?.enabled && data !== null && (
                            <Alert variant="warning">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>
                                    El programa de fidelización está deshabilitado para esta empresa. Los datos mostrados son históricos.
                                </AlertDescription>
                            </Alert>
                        )}

                        {data ? (
                            <>
                                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                    <StatTile
                                        value={data.totals.points_earned.toLocaleString('es-CO')}
                                        label="Puntos otorgados"
                                        tone="safe"
                                        size="lg"
                                    />
                                    <StatTile
                                        value={data.totals.points_redeemed.toLocaleString('es-CO')}
                                        label="Puntos canjeados"
                                        tone="accent"
                                        size="lg"
                                    />
                                    <StatTile
                                        value={data.totals.points_expired.toLocaleString('es-CO')}
                                        label="Puntos expirados"
                                        tone="critical"
                                        size="lg"
                                    />
                                    <StatTile
                                        value={data.totals.active_earners.toLocaleString('es-CO')}
                                        label="Clientes activos"
                                        tone="default"
                                        size="lg"
                                    />
                                </div>

                                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>Tasa de canje</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-3 text-sm">
                                            <div className="text-3xl font-bold text-[color:var(--color-status-warning)] tabular-nums">
                                                {data.redemption_rate.rate.toFixed(1)}%
                                            </div>
                                            <div className="grid grid-cols-2 gap-2">
                                                <KpiCell label="Emitidos" value={data.redemption_rate.issued} />
                                                <KpiCell label="Aplicados" value={data.redemption_rate.applied} />
                                                <KpiCell label="Expirados" value={data.redemption_rate.expired} />
                                                <KpiCell label="Cancelados" value={data.redemption_rate.cancelled} />
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                Total emitidos en el período: <strong>{data.redemption_rate.total}</strong>.
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader>
                                            <CardTitle>Distribución de clientes por tier</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2 text-sm">
                                            {data.tiers_distribution.length === 0 ? (
                                                <p className="text-muted-foreground italic">Sin cuentas registradas.</p>
                                            ) : (
                                                data.tiers_distribution.map((row) => (
                                                    <div key={row.tier} className="flex items-center justify-between gap-2">
                                                        <LoyaltyBadge tier={row.tier} size="sm" />
                                                        <span className="text-muted-foreground tabular-nums">
                                                            {row.clients} cliente{row.clients === 1 ? '' : 's'} ·{' '}
                                                            {row.total_balance.toLocaleString('es-CO')} pts en balance
                                                        </span>
                                                    </div>
                                                ))
                                            )}
                                        </CardContent>
                                    </Card>
                                </div>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>ARPU por tier</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {data.arpu_by_tier.length === 0 ? (
                                            <p className="text-muted-foreground text-sm italic">Sin datos.</p>
                                        ) : (
                                            <>
                                                {/* Mobile card-stack */}
                                                <ul className="space-y-3 sm:hidden">
                                                    {data.arpu_by_tier.map((row) => (
                                                        <li key={row.tier} className="border-border space-y-2 rounded-lg border p-3">
                                                            <div className="flex items-center justify-between gap-2">
                                                                <LoyaltyBadge tier={row.tier} size="sm" />
                                                                <span className="text-foreground text-sm font-semibold tabular-nums">
                                                                    {formatCurrency(Number(row.arpu))}
                                                                </span>
                                                            </div>
                                                            <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                                                <div>
                                                                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                                                        Clientes
                                                                    </dt>
                                                                    <dd className="text-foreground tabular-nums">{row.clients}</dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                                                        Revenue
                                                                    </dt>
                                                                    <dd className="text-foreground tabular-nums">
                                                                        {formatCurrency(Number(row.revenue))}
                                                                    </dd>
                                                                </div>
                                                            </dl>
                                                        </li>
                                                    ))}
                                                </ul>

                                                {/* Desktop table */}
                                                <div className="hidden sm:block">
                                                    <Table>
                                                        <TableHeader>
                                                            <TableRow>
                                                                <TableHead>Tier</TableHead>
                                                                <TableHead className="text-right">Clientes</TableHead>
                                                                <TableHead className="text-right">Revenue período</TableHead>
                                                                <TableHead className="text-right">ARPU</TableHead>
                                                            </TableRow>
                                                        </TableHeader>
                                                        <TableBody>
                                                            {data.arpu_by_tier.map((row) => (
                                                                <TableRow key={row.tier}>
                                                                    <TableCell>
                                                                        <LoyaltyBadge tier={row.tier} size="sm" />
                                                                    </TableCell>
                                                                    <TableCell className="text-right tabular-nums">{row.clients}</TableCell>
                                                                    <TableCell className="text-right tabular-nums">
                                                                        {formatCurrency(Number(row.revenue))}
                                                                    </TableCell>
                                                                    <TableCell className="text-right font-semibold tabular-nums">
                                                                        {formatCurrency(Number(row.arpu))}
                                                                    </TableCell>
                                                                </TableRow>
                                                            ))}
                                                        </TableBody>
                                                    </Table>
                                                </div>
                                            </>
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Top clientes por lifetime</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {data.top_clients.length === 0 ? (
                                            <p className="text-muted-foreground text-sm italic">Sin datos.</p>
                                        ) : (
                                            <>
                                                {/* Mobile card-stack — tabla de 6 cols requiere variante (DS §10). */}
                                                <ul className="space-y-3 sm:hidden">
                                                    {data.top_clients.map((c) => (
                                                        <li key={c.client_phone} className="border-border space-y-2 rounded-lg border p-3">
                                                            <div className="flex items-center justify-between gap-2">
                                                                <span className="text-foreground font-mono text-xs">{c.client_phone}</span>
                                                                <LoyaltyBadge tier={c.tier} size="sm" />
                                                            </div>
                                                            <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                                                                <div>
                                                                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                                                        Lifetime
                                                                    </dt>
                                                                    <dd className="text-foreground tabular-nums">
                                                                        {c.lifetime_earned.toLocaleString('es-CO')}
                                                                    </dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                                                        Balance
                                                                    </dt>
                                                                    <dd className="text-foreground tabular-nums">
                                                                        {c.balance.toLocaleString('es-CO')}
                                                                    </dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                                                        Ganados
                                                                    </dt>
                                                                    <dd className="text-[color:var(--color-status-safe)] tabular-nums">
                                                                        +{c.points_earned_period.toLocaleString('es-CO')}
                                                                    </dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="text-muted-foreground text-[11px] tracking-wide uppercase">
                                                                        Canjeados
                                                                    </dt>
                                                                    <dd className="text-destructive tabular-nums">
                                                                        {c.points_redeemed_period > 0
                                                                            ? `−${c.points_redeemed_period.toLocaleString('es-CO')}`
                                                                            : '—'}
                                                                    </dd>
                                                                </div>
                                                            </dl>
                                                        </li>
                                                    ))}
                                                </ul>

                                                {/* Desktop table */}
                                                <div className="hidden sm:block">
                                                    <Table>
                                                        <TableHeader>
                                                            <TableRow>
                                                                <TableHead>Teléfono</TableHead>
                                                                <TableHead>Tier</TableHead>
                                                                <TableHead className="text-right">Lifetime</TableHead>
                                                                <TableHead className="text-right">Balance</TableHead>
                                                                <TableHead className="text-right">Ganados período</TableHead>
                                                                <TableHead className="text-right">Canjeados período</TableHead>
                                                            </TableRow>
                                                        </TableHeader>
                                                        <TableBody>
                                                            {data.top_clients.map((c) => (
                                                                <TableRow key={c.client_phone}>
                                                                    <TableCell className="font-mono text-xs">{c.client_phone}</TableCell>
                                                                    <TableCell>
                                                                        <LoyaltyBadge tier={c.tier} size="sm" />
                                                                    </TableCell>
                                                                    <TableCell className="text-right tabular-nums">
                                                                        {c.lifetime_earned.toLocaleString('es-CO')}
                                                                    </TableCell>
                                                                    <TableCell className="text-right tabular-nums">
                                                                        {c.balance.toLocaleString('es-CO')}
                                                                    </TableCell>
                                                                    <TableCell className="text-right text-[color:var(--color-status-safe)] tabular-nums">
                                                                        +{c.points_earned_period.toLocaleString('es-CO')}
                                                                    </TableCell>
                                                                    <TableCell className="text-destructive text-right tabular-nums">
                                                                        {c.points_redeemed_period > 0
                                                                            ? `−${c.points_redeemed_period.toLocaleString('es-CO')}`
                                                                            : '—'}
                                                                    </TableCell>
                                                                </TableRow>
                                                            ))}
                                                        </TableBody>
                                                    </Table>
                                                </div>
                                            </>
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Expiraciones</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                            <KpiCell label="Eventos" value={data.expirations.events} />
                                            <KpiCell label="Cuentas afectadas" value={data.expirations.accounts_expired} />
                                            <KpiCell label="Puntos expirados" value={data.expirations.points_expired.toLocaleString('es-CO')} />
                                        </div>
                                    </CardContent>
                                </Card>
                            </>
                        ) : null}
                    </div>
                )}
            </div>
        </PageShell>
    );
}
