import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface EmployeesTableSkeletonProps {
    rows?: number;
    className?: string;
}

/**
 * Skeleton fiel del listado de `/employees`. Replica:
 *  - Mobile: card-stack (DataCardList) con nombre + 4 fields + footer.
 *  - Desktop: tabla 6 columnas (Nombre+email, Documento, Cargo, Sede, Estado, Acción).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo) y §10 (responsive tables).
 */
export function EmployeesTableSkeleton({ rows = 6, className }: EmployeesTableSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando colaboradores" className={cn(className)}>
            {/* Mobile card-stack */}
            <ul className="space-y-3 sm:hidden">
                {Array.from({ length: rows }).map((_, i) => (
                    <li
                        key={i}
                        className="border-border bg-card space-y-3 rounded-2xl border p-4 shadow-sm"
                    >
                        <div className="space-y-1.5">
                            <Skeleton className="h-4 w-3/5" />
                            <Skeleton className="h-3 w-4/5" />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-14" />
                                <Skeleton className="h-3.5 w-20" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-12" />
                                <Skeleton className="h-3.5 w-24" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-10" />
                                <Skeleton className="h-3.5 w-16" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-12" />
                                <Skeleton className="h-5 w-16 rounded-full" />
                            </div>
                        </div>
                        <div className="flex justify-end">
                            <Skeleton className="h-9 w-20 rounded-md" />
                        </div>
                    </li>
                ))}
            </ul>

            {/* Desktop table */}
            <Card className="hidden rounded-2xl shadow-sm sm:block">
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-xs uppercase">
                                <tr>
                                    <th className="px-4 py-3 text-left font-semibold">Nombre</th>
                                    <th className="px-4 py-3 text-left font-semibold">Documento</th>
                                    <th className="px-4 py-3 text-left font-semibold">Cargo</th>
                                    <th className="px-4 py-3 text-left font-semibold">Sede</th>
                                    <th className="px-4 py-3 text-left font-semibold">Estado</th>
                                    <th className="px-4 py-3 text-right font-semibold"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {Array.from({ length: rows }).map((_, i) => (
                                    <tr key={i} className="border-border border-t">
                                        <td className="px-4 py-3">
                                            <Skeleton className="h-4 w-32" />
                                            <Skeleton className="mt-1 h-3 w-40" />
                                        </td>
                                        <td className="px-4 py-3">
                                            <Skeleton className="h-4 w-24" />
                                        </td>
                                        <td className="px-4 py-3">
                                            <Skeleton className="h-5 w-20 rounded-full" />
                                        </td>
                                        <td className="px-4 py-3">
                                            <Skeleton className="h-4 w-24" />
                                        </td>
                                        <td className="px-4 py-3">
                                            <Skeleton className="h-5 w-16 rounded-full" />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Skeleton className="ml-auto h-8 w-16 rounded-md" />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
