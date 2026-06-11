import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface UsersTableSkeletonProps {
    /** Cantidad de filas/cards a esqueletizar. Default 6 (densidad típica de tabla). */
    rows?: number;
    /** Si `false`, omite el bloque de bulk-action arriba. */
    showActions?: boolean;
    className?: string;
}

/**
 * Skeleton fiel para `/identities/users`. Replica la estructura real del
 * `UsersTable` (FilterBar + variante mobile de cards + tabla desktop de
 * 6 columnas) para que conexiones lentas no muestren un placeholder genérico.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §10 (skeleton rows) y §7 (componentes co-ubicados).
 */
export function UsersTableSkeleton({ rows = 6, showActions = true, className }: UsersTableSkeletonProps) {
    return (
        <Card
            aria-busy="true"
            aria-label="Cargando usuarios"
            className={cn('w-full rounded-2xl shadow-sm', className)}
        >
            <CardContent className="p-0">
                <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:gap-4">
                    <Skeleton className="h-10 w-full max-w-xs rounded-md" />
                    <Skeleton className="h-10 w-full rounded-md sm:w-48" />
                </div>

                <Separator />

                <div className="divide-border divide-y md:hidden">
                    {Array.from({ length: rows }).map((_, i) => (
                        <div key={i} className="space-y-3 p-4">
                            <div className="flex items-center gap-3">
                                <Skeleton className="h-10 w-10 shrink-0 rounded-full" />
                                <div className="min-w-0 flex-1 space-y-1.5">
                                    <Skeleton className="h-4 w-3/5" />
                                    <Skeleton className="h-3 w-4/5" />
                                </div>
                                <Skeleton className="h-5 w-16 shrink-0 rounded-full" />
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Skeleton className="h-5 w-20 rounded-full" />
                                <Skeleton className="h-5 w-24 rounded-full" />
                            </div>
                            {showActions && (
                                <div className="flex gap-2">
                                    <Skeleton className="h-9 flex-1 rounded-md" />
                                    <Skeleton className="h-11 w-11 rounded-md" />
                                    <Skeleton className="h-11 w-11 rounded-md" />
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                <div className="hidden overflow-x-auto md:block">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs uppercase">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold">Usuario</th>
                                <th className="px-4 py-3 text-left font-semibold">Email</th>
                                <th className="px-4 py-3 text-left font-semibold">Rol</th>
                                <th className="px-4 py-3 text-left font-semibold">Sedes</th>
                                <th className="px-4 py-3 text-left font-semibold">Estado</th>
                                {showActions && <th className="px-4 py-3 text-center font-semibold">Acciones</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {Array.from({ length: rows }).map((_, i) => (
                                <tr key={i} className="border-border border-t">
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <Skeleton className="h-8 w-8 rounded-full" />
                                            <Skeleton className="h-4 w-28" />
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-4 w-44" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-6 w-20 rounded-full" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1">
                                            <Skeleton className="h-5 w-16 rounded-full" />
                                            <Skeleton className="h-5 w-12 rounded-full" />
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-5 w-16 rounded-full" />
                                    </td>
                                    {showActions && (
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-center gap-1">
                                                <Skeleton className="h-8 w-8 rounded-md" />
                                                <Skeleton className="h-8 w-8 rounded-md" />
                                                <Skeleton className="h-8 w-8 rounded-md" />
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    );
}
