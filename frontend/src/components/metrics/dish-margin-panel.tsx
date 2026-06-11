import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import type { MetricDishMargin } from '@/types';

interface DishMarginPanelProps {
    data: MetricDishMargin | null;
    loading?: boolean;
    formatCurrency: (v: number) => string;
}

/**
 * Mapea el margen porcentual a un color del semáforo de estado. Umbrales internos:
 *  - `< 0%`  → critical (pérdida)
 *  - `0–20%` → critical
 *  - `20–40%` → warning
 *  - `> 40%` → safe
 */
function marginColorClass(pct: number): string {
    if (pct < 20) return 'text-[color:var(--color-status-critical)] font-semibold';
    if (pct < 40) return 'text-[color:var(--color-status-warning)] font-medium';
    return 'text-[color:var(--color-status-safe)] font-medium';
}

export default function DishMarginPanel({ data, loading = false, formatCurrency }: DishMarginPanelProps) {
    const items = data?.items ?? [];

    return (
        <DashboardPanel title="Margen por plato">
            <p className="text-muted-foreground -mt-3 mb-3 text-xs">
                Solo aparecen los platos a los que les pusiste un costo. Anotamos cuánto te costaba el plato en el momento de la venta, para que los
                números no cambien si subes precios después.
            </p>
            {loading || !data ? (
                <div className="space-y-2">
                    {Array.from({ length: 4 }).map((_, i) => (
                        <Skeleton key={i} className="h-9 w-full rounded" />
                    ))}
                </div>
            ) : items.length === 0 ? (
                <div className="border-border text-muted-foreground rounded-md border border-dashed px-4 py-8 text-center text-sm">
                    Aún no hay platos con costo registrado para este período. Ingresa el campo "Costo" al editar tus ítems del menú para ver el
                    margen.
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left text-xs tracking-wide uppercase">
                                <th className="px-2 py-2 font-medium">Plato</th>
                                <th className="px-2 py-2 text-right font-medium">Unidades</th>
                                <th className="px-2 py-2 text-right font-medium">Precio</th>
                                <th className="px-2 py-2 text-right font-medium">Costo</th>
                                <th className="px-2 py-2 text-right font-medium">Profit</th>
                                <th className="px-2 py-2 text-right font-medium">Margen %</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((it) => (
                                <tr key={it.item_id} className="border-border border-b">
                                    <td className="px-2 py-2">{it.name}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{it.units_sold}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{formatCurrency(it.avg_price)}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{formatCurrency(it.avg_cost)}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{formatCurrency(it.margin_amount)}</td>
                                    <td className={cn('px-2 py-2 text-right tabular-nums', marginColorClass(it.margin_pct))}>
                                        {it.margin_pct.toFixed(1)}%
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </DashboardPanel>
    );
}
