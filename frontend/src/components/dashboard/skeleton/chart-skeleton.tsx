interface ChartSkeletonProps {
    height?: number;
    bars?: number;
}

export default function ChartSkeleton({ height = 200, bars = 24 }: ChartSkeletonProps) {
    return (
        <div className="flex items-end gap-[3px] pl-8" style={{ height }}>
            {Array.from({ length: bars }, (_, i) => (
                <div key={i} className="bg-border flex-1 animate-pulse rounded-sm" style={{ height: `${20 + Math.abs(Math.sin(i * 0.9) * 65)}%` }} />
            ))}
        </div>
    );
}
