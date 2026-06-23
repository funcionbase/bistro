import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { apiFetch } from '@/lib/api';
import { todayInBogota } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { Warehouse } from '@/types/inventory';
import { AlertCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface SeriesPoint {
    date: string;
    total_value: string;
    by_warehouse: { warehouse_id: string; name: string; value: string }[];
}

interface Props {
    warehouses: Warehouse[];
    selectedWarehouseId?: string;
}

const RANGE_OPTIONS = [
    { label: '7 días', days: 7 },
    { label: '30 días', days: 30 },
    { label: '60 días', days: 60 },
];

/**
 * Evolución del valor del inventario por bodega.
 *
 * Consulta GET /api/v1/inventory/history/valuation y dibuja una línea por
 * bodega (o una sola línea total si no hay filtro). Datos provienen de
 * warehouse_stock_snapshots con fallback a reconstrucción desde movements.
 */
export function InventoryValuationChart({ warehouses, selectedWarehouseId }: Props) {
    const [days, setDays] = useState(30);
    const [series, setSeries] = useState<SeriesPoint[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        const load = async () => {
            setLoading(true);
            setError(null);
            try {
                const params = new URLSearchParams();
                const toStr = todayInBogota();
                const [y, m, d] = toStr.split('-').map(Number);
                const fromDate = new Date(y, m - 1, d - days);
                params.set('from', fromDate.toLocaleDateString('sv'));
                params.set('to', toStr);
                if (selectedWarehouseId) params.set('warehouse_id', selectedWarehouseId);

                const res = await apiFetch(`/api/v1/inventory/history/valuation?${params.toString()}`);
                if (!res.ok) {
                    const json = await res.json();
                    throw new Error(json.message ?? 'No se pudo cargar el histórico.');
                }
                const json = (await res.json()) as { data: { series: SeriesPoint[] } };
                if (!cancelled) setSeries(json.data.series);
            } catch (err) {
                if (!cancelled) setError(err instanceof Error ? err.message : 'Error');
            } finally {
                if (!cancelled) setLoading(false);
            }
        };
        void load();
        return () => {
            cancelled = true;
        };
    }, [days, selectedWarehouseId]);

    // Pivotea la serie: cada fila es {date, total, [warehouseName]: value, ...}
    const chartData = useMemo(() => {
        return series.map((point) => {
            const row: Record<string, string | number> = {
                date: point.date.slice(5), // MM-DD
                total: Number(point.total_value),
            };
            for (const w of point.by_warehouse) {
                row[w.name] = Number(w.value);
            }
            return row;
        });
    }, [series]);

    const warehouseNames = useMemo(() => {
        const names = new Set<string>();
        for (const p of series) {
            for (const w of p.by_warehouse) {
                names.add(w.name);
            }
        }
        return Array.from(names);
    }, [series]);

    const palette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];

    const formatCurrency = (v: number) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(v);

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2 pb-2">
                <CardTitle className="text-base">Evolución del valor del inventario</CardTitle>
                <div className="flex gap-1 text-xs">
                    {RANGE_OPTIONS.map((opt) => (
                        <button
                            key={opt.days}
                            type="button"
                            className={cn(
                                'rounded-md border px-2 py-1 transition-colors',
                                days === opt.days
                                    ? 'bg-primary text-primary-foreground border-primary font-medium'
                                    : 'bg-card hover:bg-muted/40 border-input',
                            )}
                            onClick={() => setDays(opt.days)}
                        >
                            {opt.label}
                        </button>
                    ))}
                </div>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <Skeleton className="h-64 w-full" />
                ) : error ? (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                ) : series.length === 0 ? (
                    <p className="text-muted-foreground py-6 text-center text-sm">Sin datos para el período.</p>
                ) : (
                    <ResponsiveContainer width="100%" height={260}>
                        <LineChart data={chartData} margin={{ top: 8, right: 8, bottom: 8, left: 0 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                            <XAxis dataKey="date" fontSize={11} stroke="var(--muted-foreground)" />
                            <YAxis
                                fontSize={11}
                                stroke="var(--muted-foreground)"
                                tickFormatter={(v: number) => (v >= 1e6 ? `${(v / 1e6).toFixed(1)}M` : `${(v / 1e3).toFixed(0)}k`)}
                            />
                            <Tooltip
                                formatter={(v) => formatCurrency(Number(v))}
                                contentStyle={{
                                    background: 'var(--card)',
                                    border: '1px solid var(--border)',
                                    borderRadius: 8,
                                    color: 'var(--foreground)',
                                    fontSize: 12,
                                }}
                            />
                            {selectedWarehouseId ? (
                                <Line
                                    type="monotone"
                                    dataKey="total"
                                    name={warehouses.find((w) => w.id === selectedWarehouseId)?.name ?? 'Bodega'}
                                    stroke={palette[0]}
                                    strokeWidth={2}
                                    dot={false}
                                />
                            ) : (
                                <>
                                    <Line type="monotone" dataKey="total" name="Total" stroke="var(--foreground)" strokeWidth={2} dot={false} />
                                    {warehouseNames.map((name, idx) => (
                                        <Line
                                            key={name}
                                            type="monotone"
                                            dataKey={name}
                                            name={name}
                                            stroke={palette[(idx + 1) % palette.length]}
                                            strokeWidth={1.5}
                                            dot={false}
                                            strokeDasharray="4 2"
                                        />
                                    ))}
                                </>
                            )}
                        </LineChart>
                    </ResponsiveContainer>
                )}
            </CardContent>
        </Card>
    );
}
