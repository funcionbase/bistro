import TopItemsChart from '@/components/dashboard/top-items-chart';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import type { MetricTopItems } from '@/types';

interface DishRankingPanelProps {
    data: MetricTopItems | null;
    loading?: boolean;
    formatCurrency: (v: number) => string;
}

export default function DishRankingPanel({ data, loading = false, formatCurrency }: DishRankingPanelProps) {
    return (
        <DashboardPanel title="Ranking de platos">
            <TopItemsChart data={data?.items ?? []} loading={loading || !data} formatCurrency={formatCurrency} />
        </DashboardPanel>
    );
}
