import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { MapPin } from 'lucide-react';

export interface BranchBreakdownTotals {
    total_orders: number;
    completed: number;
    failed?: number;
    cancelled?: number;
    refunded: number;
    abandoned?: number;
    total_revenue: number;
    total_refunded: number;
    net_revenue: number;
}

export interface BranchBreakdownRow {
    branch_id: string;
    branch_name: string | null;
    totals: BranchBreakdownTotals;
}

interface BranchComparisonTableProps {
    rows: BranchBreakdownRow[];
    className?: string;
}

const currencyFormatter = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
});

/**
 * Tabla comparativa de aporte por sede en reportes consolidados (#192 Fase 3.5).
 *
 * Se renderiza cuando el reporte llega con `scope === 'consolidated'` y
 * `per_branch[]` no está vacío. Ordenado por `net_revenue` descendente
 * (la sede que más aporta queda arriba) — el ordenamiento viene del
 * backend, este componente solo renderiza.
 *
 * Reglas DS:
 *  - Tokens `bg-card`, `text-foreground`, `text-muted-foreground`,
 *    `border-border` (sin colores hardcoded).
 *  - Filas con divisores neutros; hover sutil con `bg-muted/30`.
 *  - Currency formatter para COP sin decimales (convención de
 *    presentación; los datos siguen siendo `decimal:2` en BD).
 */
export function BranchComparisonTable({ rows, className }: BranchComparisonTableProps) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <Card className={cn('overflow-hidden', className)}>
            <div className="border-border bg-muted/30 border-b px-4 py-3">
                <h3 className="text-foreground flex items-center gap-2 text-sm font-semibold">
                    <MapPin className="text-muted-foreground size-4" aria-hidden="true" />
                    Aporte por sede
                </h3>
                <p className="text-muted-foreground text-xs">Comparativa entre sedes para el período consultado. Las cifras son COP sin decimales.</p>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-muted/20 text-muted-foreground text-xs tracking-wide uppercase">
                        <tr>
                            <th scope="col" className="px-4 py-2 text-left font-medium">
                                Sede
                            </th>
                            <th scope="col" className="px-4 py-2 text-right font-medium">
                                Órdenes
                            </th>
                            <th scope="col" className="px-4 py-2 text-right font-medium">
                                Completadas
                            </th>
                            <th scope="col" className="px-4 py-2 text-right font-medium">
                                Bruto
                            </th>
                            <th scope="col" className="px-4 py-2 text-right font-medium">
                                Devuelto
                            </th>
                            <th scope="col" className="px-4 py-2 text-right font-medium">
                                Neto
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-border divide-y">
                        {rows.map((row) => (
                            <tr key={row.branch_id} className="bg-card hover:bg-muted/30">
                                <td className="text-foreground px-4 py-2 font-medium">{row.branch_name ?? 'Sede sin nombre'}</td>
                                <td className="text-foreground px-4 py-2 text-right tabular-nums">{row.totals.total_orders}</td>
                                <td className="text-foreground px-4 py-2 text-right tabular-nums">{row.totals.completed}</td>
                                <td className="text-foreground px-4 py-2 text-right tabular-nums">
                                    {currencyFormatter.format(row.totals.total_revenue)}
                                </td>
                                <td className="text-muted-foreground px-4 py-2 text-right tabular-nums">
                                    {currencyFormatter.format(row.totals.total_refunded)}
                                </td>
                                <td className="text-foreground px-4 py-2 text-right font-semibold tabular-nums">
                                    {currencyFormatter.format(row.totals.net_revenue)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}
