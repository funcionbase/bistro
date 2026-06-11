import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface EmployeeDetailSkeletonProps {
    className?: string;
}

/**
 * Skeleton fiel para `/employees/{id}` y `/me/perfil`. Mantiene la silueta:
 *  - estado pill,
 *  - panel salario,
 *  - panel ficha (6–8 detail rows en grid 1/2 columnas).
 *
 * Pensado para que conexiones lentas no muestren un layout shift al cargar.
 */
export function EmployeeDetailSkeleton({ className }: EmployeeDetailSkeletonProps) {
    return (
        <div className={cn('space-y-6', className)} aria-busy="true">
            <div className="flex flex-wrap items-center gap-2">
                <Skeleton className="h-3 w-14" />
                <Skeleton className="h-5 w-20 rounded-full" />
            </div>

            <Card className="rounded-2xl shadow-sm">
                <CardContent className="space-y-3 p-4 sm:p-6">
                    <div className="flex items-center gap-2">
                        <Skeleton className="size-8 rounded-md" />
                        <Skeleton className="h-4 w-24" />
                    </div>
                    <Skeleton className="h-10 w-full sm:w-64" />
                    <Skeleton className="h-3 w-3/4" />
                </CardContent>
            </Card>

            <Card className="rounded-2xl shadow-sm">
                <CardContent className="space-y-4 p-4 sm:p-6">
                    <div className="flex items-center gap-2">
                        <Skeleton className="size-8 rounded-md" />
                        <Skeleton className="h-4 w-32" />
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        {Array.from({ length: 6 }).map((_, i) => (
                            <div key={i} className="space-y-1.5">
                                <Skeleton className="h-3 w-20" />
                                <Skeleton className="h-4 w-3/4" />
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
