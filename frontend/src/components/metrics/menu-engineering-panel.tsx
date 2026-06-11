import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useMenuEngineering } from '@/hooks/use-menu-engineering';
import type { MenuEngineeringDish, MenuEngineeringMatrix, MenuEngineeringQuadrant, MetricPeriod } from '@/types';
import { RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { CartesianGrid, ReferenceLine, ResponsiveContainer, Scatter, ScatterChart, Tooltip, XAxis, YAxis, ZAxis } from 'recharts';

interface MenuEngineeringPanelProps {
    period: MetricPeriod;
    dateFrom: string;
    dateTo: string;
    formatCurrency: (v: number) => string;
    enabled: boolean;
}

const QUADRANT_LABEL: Record<MenuEngineeringQuadrant, { emoji: string; title: string; color: string; bg: string }> = {
    star: {
        emoji: '⭐',
        title: 'Estrellas',
        color: 'var(--color-status-success)',
        bg: 'bg-[color:var(--color-status-success)]/10 border-[color:var(--color-status-success)]/30 text-[color:var(--color-status-success)]',
    },
    cow: {
        emoji: '🐄',
        title: 'Vacas lecheras',
        color: 'var(--color-status-info)',
        bg: 'bg-[color:var(--color-status-info)]/10 border-[color:var(--color-status-info)]/30 text-[color:var(--color-status-info)]',
    },
    puzzle: {
        emoji: '🧩',
        title: 'Puzzles',
        color: 'var(--color-category-violet)',
        bg: 'bg-[color:var(--color-category-violet)]/10 border-[color:var(--color-category-violet)]/30 text-[color:var(--color-category-violet)]',
    },
    dog: {
        emoji: '🐕',
        title: 'Perros',
        color: 'var(--color-status-critical)',
        bg: 'bg-[color:var(--color-status-critical)]/10 border-[color:var(--color-status-critical)]/30 text-[color:var(--color-status-critical)]',
    },
};

export default function MenuEngineeringPanel({ period, dateFrom, dateTo, formatCurrency, enabled }: MenuEngineeringPanelProps) {
    const { fetchMatrix } = useMenuEngineering();
    const [data, setData] = useState<MenuEngineeringMatrix | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        if (!enabled) return;
        setLoading(true);
        try {
            const json = await fetchMatrix(period, dateFrom, dateTo);
            setData(json);
        } catch {
            // Errores de auth/permiso ya los maneja apiFetch globalmente.
        } finally {
            setLoading(false);
        }
    }, [enabled, period, dateFrom, dateTo, fetchMatrix]);

    useEffect(() => {
        load();
    }, [load]);

    const summary = data?.summary;
    const dishes = data?.dishes ?? [];
    const thresholds = data?.thresholds;
    const coveragePct =
        summary && summary.total_units > 0 ? Math.round(((summary.total_units - summary.unknown_units) / summary.total_units) * 100) : 0;

    return (
        <Card className="rounded-xl shadow-sm">
            <CardHeader className="pb-2">
                <div className="flex items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base font-semibold">Menu engineering</CardTitle>
                        <p className="text-muted-foreground text-xs">
                            Cruzamos qué tanto se pide cada plato (popularidad) con cuánto te deja por unidad (precio − costo). Te decimos qué
                            impulsar, qué retirar y dónde subir precio. Los umbrales son la mediana de tus propios platos en el período.
                        </p>
                    </div>
                    <Button type="button" variant="outline" size="sm" onClick={load} disabled={loading} className="shrink-0">
                        <RefreshCw className={`mr-1 h-3 w-3 ${loading ? 'animate-spin' : ''}`} />
                        Refrescar
                    </Button>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {summary && summary.unknown > 0 && (
                    <div className="rounded-md border border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 px-3 py-2 text-xs text-[color:var(--color-status-warning)]">
                        {summary.unknown} platos vendidos no tienen costo registrado y no se clasifican (cobertura del análisis: {coveragePct}%).
                        Configura el costo en el menú o en sus recetas para que aparezcan acá.
                    </div>
                )}

                {/* KPI por cuadrante */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {(['star', 'cow', 'puzzle', 'dog'] as MenuEngineeringQuadrant[]).map((q) => {
                        const meta = QUADRANT_LABEL[q];
                        const count = summary
                            ? q === 'star'
                                ? summary.stars
                                : q === 'cow'
                                  ? summary.cows
                                  : q === 'puzzle'
                                    ? summary.puzzles
                                    : summary.dogs
                            : null;
                        return (
                            <div key={q} className={`rounded-lg border px-3 py-2 ${meta.bg}`}>
                                <div className="flex items-center gap-1 text-xs tracking-wide uppercase">
                                    <span>{meta.emoji}</span>
                                    <span>{meta.title}</span>
                                </div>
                                {loading || count === null ? (
                                    <Skeleton className="mt-1 h-6 w-12 rounded" />
                                ) : (
                                    <div className="text-2xl font-semibold">{count}</div>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Scatter */}
                {loading ? (
                    <Skeleton className="h-80 w-full rounded" />
                ) : dishes.length === 0 ? (
                    <div className="text-muted-foreground rounded-md border border-dashed border-[var(--color-border-light)] px-4 py-8 text-center text-sm">
                        {summary && summary.total_units > 0
                            ? 'Aún no hay platos con costo registrado para clasificar. Agrega costo en el menú o en las recetas.'
                            : 'Aún no hay ventas en este período.'}
                    </div>
                ) : (
                    <MatrixScatter
                        dishes={dishes}
                        medPop={thresholds?.popularity_pct ?? 0}
                        medMargin={thresholds?.contribution_margin ?? 0}
                        formatCurrency={formatCurrency}
                    />
                )}

                {/* Tabla */}
                {!loading && dishes.length > 0 && (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left text-xs tracking-wide uppercase">
                                    <th className="px-2 py-2 font-medium">Plato</th>
                                    <th className="px-2 py-2 text-right font-medium">Vend.</th>
                                    <th className="px-2 py-2 text-right font-medium">Pop. %</th>
                                    <th className="px-2 py-2 text-right font-medium">Margen/und</th>
                                    <th className="px-2 py-2 text-right font-medium">Contribución</th>
                                    <th className="px-2 py-2 text-center font-medium">Cuadrante</th>
                                    <th className="px-2 py-2 font-medium">Acción sugerida</th>
                                </tr>
                            </thead>
                            <tbody>
                                {dishes.map((d) => {
                                    const meta = QUADRANT_LABEL[d.quadrant];
                                    return (
                                        <tr key={d.item_id} className="border-b border-[var(--color-border-light)]">
                                            <td className="px-2 py-2">{d.name}</td>
                                            <td className="px-2 py-2 text-right">{d.units_sold}</td>
                                            <td className="px-2 py-2 text-right">{d.popularity_pct.toFixed(1)}%</td>
                                            <td className="px-2 py-2 text-right">{formatCurrency(d.contribution_margin)}</td>
                                            <td className="px-2 py-2 text-right">{formatCurrency(d.total_contribution)}</td>
                                            <td className="px-2 py-2 text-center">
                                                <span className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs ${meta.bg}`}>
                                                    {meta.emoji} {meta.title.replace(/s$/, '')}
                                                </span>
                                            </td>
                                            <td className="text-muted-foreground px-2 py-2 text-xs">{d.recommendation}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function MatrixScatter({
    dishes,
    medPop,
    medMargin,
    formatCurrency,
}: {
    dishes: MenuEngineeringDish[];
    medPop: number;
    medMargin: number;
    formatCurrency: (v: number) => string;
}) {
    const points = dishes.map((d) => ({
        name: d.name,
        x: d.popularity_pct,
        y: d.contribution_margin,
        units: d.units_sold,
        quadrant: d.quadrant,
        recommendation: d.recommendation,
    }));
    const maxX = Math.max(...points.map((p) => p.x), medPop, 1) * 1.15;
    const maxY = Math.max(...points.map((p) => p.y), medMargin, 1) * 1.15;
    const minY = Math.min(...points.map((p) => p.y), 0);

    const byQuadrant = (q: MenuEngineeringQuadrant) => points.filter((p) => p.quadrant === q);

    return (
        <div>
            <div className="mb-2 flex items-center justify-between">
                <h4 className="text-sm font-medium">Popularidad vs margen por unidad</h4>
                <span className="text-muted-foreground text-xs">
                    Líneas grises = mediana ({medPop.toFixed(1)}% / {formatCurrency(medMargin)})
                </span>
            </div>
            <p className="text-muted-foreground mb-2 text-xs">
                Arriba a la derecha (estrellas) = popular y rentable. Abajo a la izquierda (perros) = poco demandado y poco rentable. Tamaño del punto
                = unidades vendidas.
            </p>
            <div className="h-80 w-full">
                <ResponsiveContainer width="100%" height="100%">
                    <ScatterChart margin={{ top: 10, right: 30, left: 10, bottom: 30 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="rgba(0,0,0,0.06)" />
                        <XAxis
                            type="number"
                            dataKey="x"
                            name="Popularidad"
                            tick={{ fontSize: 11 }}
                            domain={[0, maxX]}
                            tickFormatter={(v) => `${v}%`}
                            label={{ value: 'Popularidad (% de unidades)', position: 'insideBottom', offset: -10, fontSize: 11 }}
                        />
                        <YAxis
                            type="number"
                            dataKey="y"
                            name="Margen/und"
                            tick={{ fontSize: 11 }}
                            domain={[minY < 0 ? minY * 1.1 : 0, maxY]}
                            tickFormatter={(v) => formatCurrency(v)}
                            label={{ value: 'Margen unitario (precio − costo)', angle: -90, position: 'insideLeft', fontSize: 11 }}
                        />
                        <ZAxis type="number" dataKey="units" range={[40, 280]} name="Unidades" />
                        <Tooltip
                            cursor={{ strokeDasharray: '3 3' }}
                            content={({ active, payload }) => {
                                if (!active || !payload || payload.length === 0) return null;
                                const p = payload[0].payload as (typeof points)[number];
                                const meta = QUADRANT_LABEL[p.quadrant];
                                return (
                                    <div className="max-w-xs rounded border border-[var(--color-border-light)] bg-white px-3 py-2 text-xs shadow">
                                        <div className="font-semibold">{p.name}</div>
                                        <div className="text-muted-foreground">
                                            {meta.emoji} {meta.title.replace(/s$/, '')}
                                        </div>
                                        <div className="mt-1">Popularidad: {p.x.toFixed(1)}%</div>
                                        <div>Margen/und: {formatCurrency(p.y)}</div>
                                        <div>Vendidos: {p.units}</div>
                                        <div className="mt-1 text-[11px] italic">{p.recommendation}</div>
                                    </div>
                                );
                            }}
                        />
                        <ReferenceLine x={medPop} stroke="rgba(0,0,0,0.35)" strokeDasharray="4 4" />
                        <ReferenceLine y={medMargin} stroke="rgba(0,0,0,0.35)" strokeDasharray="4 4" />
                        {(['star', 'cow', 'puzzle', 'dog'] as MenuEngineeringQuadrant[]).map((q) => (
                            <Scatter
                                key={q}
                                name={`${QUADRANT_LABEL[q].emoji} ${QUADRANT_LABEL[q].title}`}
                                data={byQuadrant(q)}
                                fill={QUADRANT_LABEL[q].color}
                            />
                        ))}
                    </ScatterChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}
