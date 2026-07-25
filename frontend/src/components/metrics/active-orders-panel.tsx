import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Skeleton } from '@/components/ui/skeleton';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import { statusLabel } from '@/lib/order-status';
import type { MetricActiveOrders } from '@/types';

interface ActiveOrdersPanelProps {
    data: MetricActiveOrders | null;
    loading?: boolean;
    refreshedAt?: string;
}

// Color del puntito por estado — tokens del DS (theme-aware, sin paleta cruda):
// pending espera atención (warning), in_kitchen en preparación (category-amber),
// ready listo (safe), in_transit en camino (info).
const STATUS_COLOR: Record<string, string> = {
    pending: 'bg-[color:var(--color-status-warning)]',
    in_kitchen: 'bg-[color:var(--color-category-amber)]',
    ready: 'bg-[color:var(--color-status-safe)]',
    in_transit: 'bg-[color:var(--color-status-info)]',
};

const STATUS_KEYS: (keyof MetricActiveOrders)[] = ['pending', 'in_kitchen', 'ready', 'in_transit'];

export default function ActiveOrdersPanel({ data, loading = false, refreshedAt }: ActiveOrdersPanelProps) {
    const orderStatuses = useOrderStatuses();
    const totalActive = data ? STATUS_KEYS.reduce((sum, key) => sum + (data[key] as number), 0) : 0;

    const rightSlot =
        refreshedAt && !loading ? (
            <span className="text-muted-foreground text-xs tabular-nums">
                {new Date(refreshedAt).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Bogota' })}
            </span>
        ) : undefined;

    return (
        <DashboardPanel title="Órdenes activas" rightSlot={rightSlot}>
            {loading || !data ? (
                <div className="space-y-3">
                    <Skeleton className="h-10 w-full" />
                    <Skeleton className="h-10 w-full" />
                    <Skeleton className="h-10 w-full" />
                </div>
            ) : (
                <>
                    <div className="mb-4 flex items-center gap-3">
                        <span className="text-4xl font-bold tabular-nums">{totalActive}</span>
                        <span className="text-muted-foreground text-sm">órdenes en curso</span>
                    </div>
                    <div className="space-y-2">
                        {STATUS_KEYS.map((status) => (
                            <div key={status} className="bg-muted flex items-center justify-between rounded-lg px-3 py-2">
                                <div className="flex items-center gap-2">
                                    <span className={`h-2.5 w-2.5 rounded-full ${STATUS_COLOR[status] ?? 'bg-muted-foreground/40'}`} />
                                    <span className="text-sm">{statusLabel(orderStatuses, status)}</span>
                                </div>
                                <span className="font-semibold tabular-nums">{data[status]}</span>
                            </div>
                        ))}
                    </div>
                </>
            )}
        </DashboardPanel>
    );
}
