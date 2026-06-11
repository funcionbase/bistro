import { Card, CardContent } from '@/components/ui/card';

export default function MetricCardSkeleton() {
    return (
        <Card className="rounded-2xl shadow-sm">
            <CardContent className="p-5">
                <div className="mb-3 flex items-center justify-between">
                    <div className="bg-border h-4 w-24 animate-pulse rounded" />
                    <div className="bg-muted h-8 w-8 animate-pulse rounded-lg" />
                </div>
                <div className="bg-border mb-1 h-8 w-32 animate-pulse rounded" />
                <div className="bg-muted h-3 w-20 animate-pulse rounded" />
            </CardContent>
        </Card>
    );
}
