import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export default function HeatmapSkeleton() {
    return (
        <Card className="rounded-2xl shadow-sm">
            <CardHeader className="pb-2">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-muted-foreground text-base font-semibold">Actividad por hora</CardTitle>
                    <div className="bg-muted h-3 w-28 animate-pulse rounded" />
                </div>
            </CardHeader>
            <CardContent>
                <div className="flex h-[220px] items-end gap-[3px] pb-5">
                    {Array.from({ length: 24 }, (_, i) => (
                        <div
                            key={i}
                            className="bg-border flex-1 animate-pulse rounded-sm"
                            style={{ height: `${20 + Math.abs(Math.sin(i * 0.7) * 60)}%` }}
                        />
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
