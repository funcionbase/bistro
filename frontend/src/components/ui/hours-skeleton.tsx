import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface HoursSkeletonProps {
    className?: string;
}

/**
 * Skeleton fiel de `/hours` (horarios de operación).
 *
 * Replica:
 *  - PageHeader (eyebrow HORARIOS + título + 2 acciones).
 *  - OpenStatusBadge pill.
 *  - MenuPriorityBanner alerta.
 *  - Mobile: stack vertical (semana + calendario).
 *  - xl+: 2 columnas (semana | calendario).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §13 (loading).
 */
export function HoursSkeleton({ className }: HoursSkeletonProps) {
    return (
        <div
            aria-busy="true"
            aria-label="Cargando horarios"
            className={cn('flex h-full flex-1 flex-col gap-6', className)}
        >
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-4 w-24 rounded-full" />
                    <Skeleton className="h-8 w-64" />
                    <Skeleton className="h-3.5 w-full max-w-xl" />
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Skeleton className="h-9 w-32" />
                    <Skeleton className="h-9 w-36" />
                </div>
            </div>

            {/* OpenStatusBadge */}
            <Skeleton className="h-8 w-40 rounded-full" />

            {/* MenuPriorityBanner */}
            <div className="border-border bg-card flex items-start gap-3 rounded-lg border p-4">
                <Skeleton className="h-5 w-5 rounded-full" />
                <div className="flex-1 space-y-2">
                    <Skeleton className="h-4 w-1/2" />
                    <Skeleton className="h-3 w-full max-w-2xl" />
                </div>
            </div>

            {/* Grid: weekly schedule + calendar */}
            <div className="grid gap-6 xl:grid-cols-2">
                {/* WeeklyScheduleEditor */}
                <div className="border-border bg-card space-y-3 rounded-lg border p-4 shadow-sm">
                    <Skeleton className="h-5 w-44" />
                    <div className="space-y-2">
                        {Array.from({ length: 7 }).map((_, i) => (
                            <div
                                key={i}
                                className="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex items-center gap-2">
                                    <Skeleton className="h-4 w-4 rounded" />
                                    <Skeleton className="h-4 w-20" />
                                </div>
                                <div className="flex gap-2">
                                    <Skeleton className="h-9 w-24" />
                                    <Skeleton className="h-9 w-24" />
                                </div>
                            </div>
                        ))}
                    </div>
                    <Skeleton className="h-10 w-full sm:w-32" />
                </div>

                {/* ExceptionsCalendar */}
                <div className="border-border bg-card space-y-3 rounded-lg border p-4 shadow-sm">
                    <div className="flex items-center justify-between">
                        <Skeleton className="h-5 w-40" />
                        <Skeleton className="h-8 w-20" />
                    </div>
                    <div className="grid grid-cols-7 gap-1">
                        {Array.from({ length: 42 }).map((_, i) => (
                            <Skeleton key={i} className="h-10 w-full rounded-md" />
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
