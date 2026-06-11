import { Card, CardContent, CardHeader } from '@/components/ui/card';

export default function DeliveriesSkeleton() {
    return (
        <Card className="rounded-2xl shadow-sm">
            <CardHeader className="pb-2">
                <div className="flex items-center justify-between">
                    <div className="bg-border h-5 w-32 animate-pulse rounded" />
                    <div className="bg-muted h-5 w-28 animate-pulse rounded-full" />
                </div>
            </CardHeader>
            <CardContent>
                <div className="mb-4 grid grid-cols-3 gap-3">
                    {[1, 2, 3].map((i) => (
                        <div key={i} className="bg-muted rounded-lg p-3 text-center">
                            <div className="bg-border mx-auto mb-1.5 h-7 w-10 animate-pulse rounded" />
                            <div className="bg-border mx-auto h-3 w-14 animate-pulse rounded" />
                        </div>
                    ))}
                </div>
                <div className="space-y-1.5">
                    {[1, 2, 3].map((i) => (
                        <div key={i} className="bg-muted flex items-center justify-between rounded-lg px-3 py-2">
                            <div className="bg-border h-4 w-28 animate-pulse rounded" />
                            <div className="flex gap-3">
                                <div className="bg-border h-3 w-16 animate-pulse rounded" />
                                <div className="bg-border h-3 w-10 animate-pulse rounded" />
                            </div>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
