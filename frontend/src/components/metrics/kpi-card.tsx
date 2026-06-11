import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { LucideIcon } from 'lucide-react';

interface KpiCardProps {
    label: string;
    value: string | number;
    sub?: string;
    icon: LucideIcon;
    loading?: boolean;
    accent?: boolean;
    changePercent?: number | null;
    changeLabel?: string;
}

export default function KpiCard({ label, value, sub, icon: Icon, loading = false, accent = false, changePercent, changeLabel }: KpiCardProps) {
    if (loading) {
        return (
            <Card className="rounded-2xl shadow-sm">
                <CardContent className="p-5">
                    <Skeleton className="mb-3 h-4 w-24" />
                    <Skeleton className="mb-1 h-8 w-32" />
                    <Skeleton className="h-3 w-20" />
                </CardContent>
            </Card>
        );
    }

    const hasTrend = changePercent !== null && changePercent !== undefined;
    const isPositive = hasTrend && changePercent! >= 0;

    return (
        <Card className="rounded-2xl shadow-sm transition-shadow hover:shadow-md">
            <CardContent className="p-5">
                <div className="mb-3 flex items-center justify-between">
                    <p className="text-muted-foreground text-sm font-medium">{label}</p>
                    <div className={cn('flex h-8 w-8 items-center justify-center rounded-lg', accent ? 'bg-primary' : 'bg-muted')}>
                        <Icon className={cn('h-4 w-4', accent ? 'text-primary-foreground' : 'text-foreground')} />
                    </div>
                </div>
                <p className="text-2xl font-bold tabular-nums">{value}</p>
                {sub && <p className="text-muted-foreground mt-0.5 text-xs">{sub}</p>}
                {hasTrend && (
                    <div
                        className={cn(
                            'mt-1.5 flex items-center gap-0.5 text-xs font-medium tabular-nums',
                            isPositive ? 'text-[color:var(--color-status-safe)]' : 'text-[color:var(--color-status-critical)]',
                        )}
                    >
                        <span>{isPositive ? '↑' : '↓'}</span>
                        <span>{Math.abs(changePercent!).toFixed(1)}%</span>
                        {changeLabel && <span className="text-muted-foreground ml-0.5 font-normal">{changeLabel}</span>}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
