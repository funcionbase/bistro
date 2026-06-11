import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface RolesTableSkeletonProps {
    /** Cantidad de filas/cards a esqueletizar. Default 5. */
    rows?: number;
    /** Si se incluye la barra de stats (3 KPIs). Default true. */
    showStats?: boolean;
    /** Si la columna de acciones está visible (canManage). Default true. */
    showActions?: boolean;
    className?: string;
}

/**
 * Skeleton fiel para `/identities/roles`. Replica los 3 KPIs (Roles,
 * Personalizados, Usuarios asignados) y la variante mobile de cards +
 * tabla desktop con 4–5 columnas. Pensado para conexiones lentas (3G,
 * lobby de hotel, datos móviles flojos) donde la primera respuesta de
 * `/api/v1/roles` puede demorar 1–3 s.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §10 (skeleton rows) y §7 (componentes co-ubicados).
 */
export function RolesTableSkeleton({ rows = 5, showStats = true, showActions = true, className }: RolesTableSkeletonProps) {
    return (
        <div
            aria-busy="true"
            aria-label="Cargando roles"
            className={cn('flex flex-col gap-6', className)}
        >
            {showStats && (
                <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                    {[0, 1, 2].map((i) => (
                        <Card
                            key={i}
                            className={cn('rounded-lg p-4 shadow-sm', i === 2 && 'col-span-2 md:col-span-1')}
                        >
                            <Skeleton className="h-3 w-20" />
                            <Skeleton className="mt-3 h-7 w-12" />
                        </Card>
                    ))}
                </div>
            )}

            {/* Mobile cards */}
            <div className="grid gap-3 sm:hidden" role="list">
                {Array.from({ length: rows }).map((_, i) => (
                    <div
                        key={i}
                        role="listitem"
                        className="bg-card flex flex-col gap-3 rounded-lg border p-4 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0 flex-1 space-y-2">
                                <Skeleton className="h-5 w-32 rounded-full" />
                                <Skeleton className="h-3 w-4/5" />
                            </div>
                            {showActions && (
                                <div className="flex shrink-0 gap-1">
                                    <Skeleton className="h-9 w-9 rounded-md" />
                                    <Skeleton className="h-9 w-9 rounded-md" />
                                </div>
                            )}
                        </div>
                        <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                            <div className="space-y-1">
                                <Skeleton className="h-3 w-16" />
                                <Skeleton className="h-4 w-8" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-3 w-16" />
                                <Skeleton className="h-4 w-10" />
                            </div>
                        </dl>
                    </div>
                ))}
            </div>

            {/* Desktop table */}
            <div className="bg-card hidden overflow-hidden rounded-lg border shadow-sm sm:block">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs uppercase">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold">Nombre</th>
                                <th className="px-4 py-3 text-left font-semibold">Descripción</th>
                                <th className="px-4 py-3 text-right font-semibold">Permisos</th>
                                <th className="px-4 py-3 text-left font-semibold">Usuarios</th>
                                {showActions && <th className="px-4 py-3 text-right font-semibold">Acciones</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {Array.from({ length: rows }).map((_, i) => (
                                <tr key={i} className="border-border border-t">
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-5 w-28 rounded-full" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-4 w-56" />
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Skeleton className="ml-auto h-4 w-6" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <Skeleton className="h-5 w-8 rounded-full" />
                                    </td>
                                    {showActions && (
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-1">
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
            </div>
        </div>
    );
}
