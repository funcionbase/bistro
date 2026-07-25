import WidgetErrorState from '@/components/dashboard/widget-error-state';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Skeleton } from '@/components/ui/skeleton';
import { StatTile } from '@/components/ui/stat-tile';
import type { MetricCartAbandonment } from '@/types';

const WARNING_THRESHOLD = 20;
const CRITICAL_THRESHOLD = 40;

function getAbandonmentColor(rate: number): string {
    if (rate >= CRITICAL_THRESHOLD) return 'var(--color-status-critical)';
    if (rate >= WARNING_THRESHOLD) return 'var(--color-status-warning)';
    return 'var(--color-status-safe)';
}

function AbandonmentRing({ rate, color }: { rate: number; color: string }) {
    const radius = 54;
    const stroke = 12;
    const r = radius - stroke / 2;
    const circumference = r * 2 * Math.PI;
    const offset = circumference - (Math.min(rate, 100) / 100) * circumference;

    return (
        <div className="relative flex items-center justify-center">
            <svg width={radius * 2} height={radius * 2} className="-rotate-90">
                <circle cx={radius} cy={radius} r={r} fill="transparent" stroke="var(--color-border)" strokeWidth={stroke} />
                <circle
                    cx={radius}
                    cy={radius}
                    r={r}
                    fill="transparent"
                    stroke={color}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={`${circumference} ${circumference}`}
                    style={{ strokeDashoffset: offset, transition: 'stroke-dashoffset 0.5s ease' }}
                />
            </svg>
            <div className="absolute flex flex-col items-center">
                <span className="text-2xl leading-none font-bold tabular-nums" style={{ color }}>
                    {rate}%
                </span>
                <span className="text-muted-foreground mt-0.5 text-[10px]">abandono</span>
            </div>
        </div>
    );
}

interface AbandonmentPanelProps {
    data: MetricCartAbandonment | null;
    /** Aceptado por compatibilidad; el skeleton se decide solo por `data`. */
    loading?: boolean;
    error?: boolean;
    retryFn?: () => void;
    formatCurrency: (v: number) => string;
}

export default function AbandonmentPanel({ data, error = false, retryFn, formatCurrency }: AbandonmentPanelProps) {
    const abandonmentRate = data ? Math.round((100 - data.conversion_rate) * 100) / 100 : 0;
    const color = getAbandonmentColor(abandonmentRate);

    return (
        <DashboardPanel title="Abandono de carrito">
            {error && retryFn ? (
                <WidgetErrorState onRetry={retryFn} />
            ) : /* Skeleton solo sin datos: con datos y refetch en vuelo la
                   gráfica queda montada (evita flicker en cada poll). */
            !data ? (
                <div className="space-y-3">
                    <Skeleton className="mx-auto h-28 w-28 rounded-full" />
                    <Skeleton className="h-10 w-full" />
                </div>
            ) : (
                <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-center sm:gap-6">
                    <AbandonmentRing rate={abandonmentRate} color={color} />

                    <div className="flex-1 space-y-2 text-center sm:text-left">
                        <div className="grid grid-cols-3 gap-2">
                            <StatTile value={data.total_initiated} label="Total" tone="default" />
                            <StatTile value={data.converted} label="Convertidos" tone="safe" />
                            <StatTile value={data.abandoned} label="Abandonados" tone="critical" />
                        </div>

                        {data.estimated_lost_revenue > 0 && (
                            <p className="text-muted-foreground text-xs">
                                Ingreso perdido estimado:{' '}
                                <span className="font-semibold text-[color:var(--color-status-critical)] tabular-nums">
                                    {formatCurrency(data.estimated_lost_revenue)}
                                </span>
                            </p>
                        )}
                    </div>
                </div>
            )}
        </DashboardPanel>
    );
}
