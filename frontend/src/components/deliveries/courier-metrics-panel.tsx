import { PerformanceBar } from '@/components/deliveries/performance-bar';
import { TableCell, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { DeliveryMetric } from '@/types';

interface CourierMetricsPanelProps {
    metric: DeliveryMetric;
    maxSuccessRate: number;
}

/**
 * Fila de tabla con métricas de un repartidor. Usa los componentes de
 * shadcn Table — debe renderizarse dentro de un `<Table bare>`/`<TableBody>`.
 *
 * La tasa de éxito se colorea según el semáforo de estado:
 *  - ≥80 → safe; ≥50 → warning; <50 → critical.
 */
export function CourierMetricsPanel({ metric, maxSuccessRate }: CourierMetricsPanelProps) {
    const successRate = parseFloat(metric.success_rate);
    const rateColor =
        successRate >= 80
            ? 'text-[color:var(--color-status-safe)]'
            : successRate >= 50
              ? 'text-[color:var(--color-status-warning)]'
              : 'text-[color:var(--color-status-critical)]';
    const relativePercent = maxSuccessRate > 0 ? Math.round((successRate / maxSuccessRate) * 100) : 0;

    return (
        <TableRow>
            <TableCell className="text-foreground font-medium">{metric.courier_name}</TableCell>
            <TableCell className="text-muted-foreground text-center tabular-nums">{metric.total_deliveries}</TableCell>
            <TableCell className="text-center text-[color:var(--color-status-safe)] tabular-nums">{metric.completed}</TableCell>
            <TableCell className="text-center text-[color:var(--color-status-critical)] tabular-nums">{metric.cancelled}</TableCell>
            <TableCell className="text-muted-foreground text-center tabular-nums">
                {metric.average_duration_minutes !== null ? `${metric.average_duration_minutes} min` : '—'}
            </TableCell>
            <TableCell className={cn('text-center font-semibold tabular-nums', rateColor)}>{metric.success_rate}</TableCell>
            <TableCell>
                <PerformanceBar percentage={relativePercent} label={`${Math.round(successRate)}%`} />
            </TableCell>
        </TableRow>
    );
}
