import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useFoodCost } from '@/hooks/use-food-cost';
import type { FoodCostHistory, FoodCostItem, FoodCostSummary, MetricPeriod } from '@/types';
import { RefreshButton } from '@/components/ui/refresh-button';
import { LineChart as LineChartIcon, TrendingDown } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ReferenceLine,
    ResponsiveContainer,
    Scatter,
    ScatterChart,
    Tooltip,
    XAxis,
    YAxis,
    ZAxis,
} from 'recharts';

interface FoodCostPanelProps {
    period: MetricPeriod;
    dateFrom: string;
    dateTo: string;
    formatCurrency: (v: number) => string;
    /**
     * Threshold de margen (0..1) leído de company_settings.food_cost_alert_threshold.
     * Si margin_pct/100 < threshold → estado "baja".
     */
    alertThreshold: number;
    /** Habilita el fetch (e.g., para esperar a que period custom esté listo). */
    enabled: boolean;
}

type Status = 'ok' | 'low' | 'unknown';

function classifyItem(item: FoodCostItem, threshold: number): Status {
    if (!item.has_cost || item.margin_pct === null) return 'unknown';
    return item.margin_pct / 100 < threshold ? 'low' : 'ok';
}

function statusBadge(status: Status) {
    switch (status) {
        case 'ok':
            return (
                <span className="inline-flex items-center rounded-full bg-[color:var(--color-status-success)]/15 px-2 py-0.5 text-xs font-medium text-[color:var(--color-status-success)]">
                    ✓
                </span>
            );
        case 'low':
            return (
                <span className="inline-flex items-center gap-1 rounded-full bg-[color:var(--color-status-critical)]/15 px-2 py-0.5 text-xs font-medium text-[color:var(--color-status-critical)]">
                    <TrendingDown className="h-3 w-3" /> baja
                </span>
            );
        default:
            return (
                <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">N/D</span>
            );
    }
}

function marginColor(pct: number | null, threshold: number): string {
    if (pct === null) return 'text-muted-foreground';
    if (pct / 100 < threshold) return 'text-[color:var(--color-status-critical)] font-semibold';
    if (pct < 30) return 'text-[color:var(--color-status-warning)]';
    return 'text-[color:var(--color-status-success)]';
}

export default function FoodCostPanel({ period, dateFrom, dateTo, formatCurrency, alertThreshold, enabled }: FoodCostPanelProps) {
    const { fetchSummary, fetchItemHistory } = useFoodCost();
    const [data, setData] = useState<FoodCostSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [history, setHistory] = useState<FoodCostHistory | null>(null);
    const [historyLoading, setHistoryLoading] = useState(false);

    const load = useCallback(async () => {
        if (!enabled) return;
        setLoading(true);
        try {
            const json = await fetchSummary(period, dateFrom, dateTo);
            setData(json);
        } catch {
            // El endpoint es 200 con datos vacíos cuando no hay órdenes; los
            // errores reales (auth/permiso) se manejan globalmente por apiFetch.
        } finally {
            setLoading(false);
        }
    }, [enabled, period, dateFrom, dateTo, fetchSummary]);

    useEffect(() => {
        load();
    }, [load]);

    async function openHistory(item: FoodCostItem) {
        setHistoryOpen(true);
        setHistory(null);
        setHistoryLoading(true);
        try {
            const json = await fetchItemHistory(item.item_id, 'month', '', '');
            setHistory(json);
        } finally {
            setHistoryLoading(false);
        }
    }

    const totals = data?.totals;
    const items = data?.items ?? [];
    const itemsWithCost = items.filter((i) => i.has_cost);
    const lowMarginCount = itemsWithCost.filter((i) => classifyItem(i, alertThreshold) === 'low').length;
    const meta = data?.snapshot_meta;
    const schedulerWarning = meta && meta.scheduler_lag_hours !== null && meta.scheduler_lag_hours > 26;

    return (
        <Card className="rounded-xl shadow-sm">
            <CardHeader className="pb-2">
                <div className="flex items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base font-semibold">Costo de alimentos (food cost)</CardTitle>
                        <p className="text-muted-foreground text-xs">
                            Cuánto te cuestan los ingredientes de los platos que vendiste. Mientras más bajo el costo frente al precio, más ganas. El
                            umbral de alerta lo configuras en <span className="font-medium">Mi Empresa › Preferencias</span>.
                        </p>
                    </div>
                    <RefreshButton onRefresh={load} refreshing={loading} disabled={loading} className="shrink-0" />
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {schedulerWarning && (
                    <div className="rounded-md border border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 px-3 py-2 text-xs text-[color:var(--color-status-warning)]">
                        El último cálculo automático fue hace {meta?.scheduler_lag_hours} horas. Es posible que el proceso programado de tu servidor
                        no esté corriendo. Mientras tanto, el dashboard recalcula al primer acceso del día.
                    </div>
                )}

                {/* KPI agregado */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <KpiBlock label="Ventas (gross)" value={loading || !totals ? null : formatCurrency(totals.gross_revenue)} />
                    <KpiBlock label="Costo de alimentos" value={loading || !totals ? null : formatCurrency(totals.gross_cost)} />
                    <KpiBlock
                        label="% costo / ventas"
                        value={loading || !totals ? null : totals.cost_ratio_pct === null ? 'N/D' : `${totals.cost_ratio_pct.toFixed(1)}%`}
                        emphasis={
                            totals?.cost_ratio_pct !== null && totals?.cost_ratio_pct !== undefined
                                ? totals.cost_ratio_pct / 100 > 1 - alertThreshold
                                    ? 'red'
                                    : 'green'
                                : 'neutral'
                        }
                    />
                    <KpiBlock
                        label="Cobertura del cálculo"
                        value={loading || !totals ? null : `${totals.coverage_pct.toFixed(0)}%`}
                        sub={totals ? `${totals.units_with_cost} de ${totals.units_sold} unidades con costo conocido` : undefined}
                    />
                </div>

                {/* Tabla */}
                {loading ? (
                    <div className="space-y-2">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <Skeleton key={i} className="h-9 w-full rounded" />
                        ))}
                    </div>
                ) : items.length === 0 ? (
                    <div className="text-muted-foreground rounded-md border border-dashed border-[var(--color-border-light)] px-4 py-8 text-center text-sm">
                        Aún no hay ventas en este período.
                    </div>
                ) : (
                    <div className="space-y-1">
                        <div className="text-muted-foreground flex items-center justify-between text-xs">
                            <span>{items.length} platos vendidos</span>
                            {lowMarginCount > 0 && (
                                <span className="text-[color:var(--color-status-critical)]">
                                    ⚠ {lowMarginCount} con margen por debajo del umbral
                                </span>
                            )}
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs tracking-wide uppercase">
                                        <th className="px-2 py-2 font-medium">Plato</th>
                                        <th className="px-2 py-2 text-right font-medium">Vend.</th>
                                        <th className="px-2 py-2 text-right font-medium">Precio</th>
                                        <th className="px-2 py-2 text-right font-medium">Costo</th>
                                        <th className="px-2 py-2 text-right font-medium">Margen</th>
                                        <th className="px-2 py-2 text-center font-medium">Estado</th>
                                        <th className="px-2 py-2 text-center font-medium">Histórico</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.map((it) => {
                                        const status = classifyItem(it, alertThreshold);
                                        return (
                                            <tr key={it.item_id} className="border-b border-[var(--color-border-light)]">
                                                <td className="px-2 py-2">{it.name}</td>
                                                <td className="px-2 py-2 text-right">{it.units_sold}</td>
                                                <td className="px-2 py-2 text-right">{formatCurrency(it.avg_price)}</td>
                                                <td className="px-2 py-2 text-right">
                                                    {it.has_cost && it.avg_cost !== null ? formatCurrency(it.avg_cost) : '—'}
                                                </td>
                                                <td className={`px-2 py-2 text-right ${marginColor(it.margin_pct, alertThreshold)}`}>
                                                    {it.margin_pct === null ? 'N/D' : `${it.margin_pct.toFixed(1)}%`}
                                                </td>
                                                <td className="px-2 py-2 text-center">{statusBadge(status)}</td>
                                                <td className="px-2 py-2 text-center">
                                                    <button
                                                        type="button"
                                                        onClick={() => openHistory(it)}
                                                        className="inline-flex items-center gap-1 text-xs text-[var(--color-brand)] hover:underline"
                                                    >
                                                        <LineChartIcon className="h-3 w-3" /> ver
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Scatter precio vs costo */}
                {!loading && itemsWithCost.length > 0 && (
                    <ScatterChartBlock items={itemsWithCost} threshold={alertThreshold} formatCurrency={formatCurrency} />
                )}
            </CardContent>

            {/* Modal histórico */}
            <Dialog open={historyOpen} onOpenChange={(o) => !o && setHistoryOpen(false)}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Evolución de costo {history?.name ? `· ${history.name}` : ''}
                            {history?.archived && (
                                <span className="bg-muted text-muted-foreground ml-2 rounded px-2 py-0.5 text-xs">plato eliminado</span>
                            )}
                        </DialogTitle>
                    </DialogHeader>
                    {historyLoading || !history ? (
                        <Skeleton className="h-64 w-full rounded" />
                    ) : history.points.length === 0 ? (
                        <div className="text-muted-foreground px-4 py-8 text-center text-sm">
                            Aún no hay snapshots. El primer histórico se genera al primer acceso del día siguiente.
                        </div>
                    ) : (
                        <div className="h-64 w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={history.points} margin={{ top: 10, right: 20, left: 0, bottom: 10 }}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="rgba(0,0,0,0.06)" />
                                    <XAxis dataKey="date" tick={{ fontSize: 11 }} />
                                    <YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => formatCurrency(v)} />
                                    <Tooltip formatter={(v) => formatCurrency(Number(v))} />
                                    <Line type="monotone" dataKey="cost" stroke="#FF6B35" strokeWidth={2} dot={{ r: 3 }} />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </Card>
    );
}

function KpiBlock({
    label,
    value,
    sub,
    emphasis = 'neutral',
}: {
    label: string;
    value: string | null;
    sub?: string;
    emphasis?: 'neutral' | 'green' | 'red';
}) {
    const color =
        emphasis === 'green'
            ? 'text-[color:var(--color-status-success)]'
            : emphasis === 'red'
              ? 'text-[color:var(--color-status-critical)]'
              : 'text-foreground';
    return (
        <div className="bg-card rounded-lg border border-[var(--color-border-light)] px-3 py-2">
            <div className="text-muted-foreground text-xs tracking-wide uppercase">{label}</div>
            {value === null ? <Skeleton className="mt-1 h-6 w-20 rounded" /> : <div className={`text-xl font-semibold ${color}`}>{value}</div>}
            {sub && <div className="text-muted-foreground mt-0.5 text-[11px]">{sub}</div>}
        </div>
    );
}

function ScatterChartBlock({
    items,
    threshold,
    formatCurrency,
}: {
    items: FoodCostItem[];
    threshold: number;
    formatCurrency: (v: number) => string;
}) {
    const points = items.map((it) => ({
        name: it.name,
        price: it.avg_price,
        cost: it.avg_cost ?? 0,
        units: it.units_sold,
        status: classifyItem(it, threshold),
    }));
    const ok = points.filter((p) => p.status === 'ok');
    const low = points.filter((p) => p.status === 'low');
    const maxAxis = Math.max(...points.map((p) => Math.max(p.price, p.cost)), 1) * 1.1;

    return (
        <div>
            <h4 className="mb-2 text-sm font-medium">Precio vs costo por plato</h4>
            <p className="text-muted-foreground mb-2 text-xs">
                La línea diagonal es el punto en que el precio iguala al costo (no ganarías nada). Mientras más arriba a la izquierda esté un plato,
                más ganas con él. Tamaño = unidades vendidas.
            </p>
            <div className="h-72 w-full">
                <ResponsiveContainer width="100%" height="100%">
                    <ScatterChart margin={{ top: 10, right: 20, left: 0, bottom: 20 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="rgba(0,0,0,0.06)" />
                        <XAxis
                            type="number"
                            dataKey="price"
                            name="Precio"
                            tick={{ fontSize: 11 }}
                            domain={[0, maxAxis]}
                            tickFormatter={(v) => formatCurrency(v)}
                            label={{ value: 'Precio', position: 'insideBottomRight', offset: -5, fontSize: 11 }}
                        />
                        <YAxis
                            type="number"
                            dataKey="cost"
                            name="Costo"
                            tick={{ fontSize: 11 }}
                            domain={[0, maxAxis]}
                            tickFormatter={(v) => formatCurrency(v)}
                            label={{ value: 'Costo', angle: -90, position: 'insideLeft', fontSize: 11 }}
                        />
                        <ZAxis type="number" dataKey="units" range={[40, 240]} name="Unidades" />
                        <Tooltip
                            cursor={{ strokeDasharray: '3 3' }}
                            content={({ active, payload }) => {
                                if (!active || !payload || payload.length === 0) return null;
                                const p = payload[0].payload as (typeof points)[number];
                                return (
                                    <div className="bg-card rounded border border-[var(--color-border-light)] px-3 py-2 text-xs shadow">
                                        <div className="font-semibold">{p.name}</div>
                                        <div>Precio: {formatCurrency(p.price)}</div>
                                        <div>Costo: {formatCurrency(p.cost)}</div>
                                        <div>Vendidos: {p.units}</div>
                                    </div>
                                );
                            }}
                        />
                        <ReferenceLine
                            segment={[
                                { x: 0, y: 0 },
                                { x: maxAxis, y: maxAxis },
                            ]}
                            stroke="var(--color-status-critical)"
                            strokeOpacity={0.5}
                            strokeDasharray="4 4"
                            label={{ value: 'precio = costo', position: 'right', fontSize: 10, fill: 'var(--color-status-critical)' }}
                        />
                        <Legend verticalAlign="top" height={24} />
                        <Scatter name="OK" data={ok} fill="var(--color-status-success)" />
                        <Scatter name="Margen bajo" data={low} fill="var(--color-status-critical)" />
                    </ScatterChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}
