import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface InventorySkeletonProps {
    /** Cantidad de insumos a esqueletizar (default 6). */
    rows?: number;
    /** Mostrar tira de tabs de bodegas (default true). */
    showWarehouseTabs?: boolean;
    className?: string;
}

/**
 * Esqueleto fiel de `/inventory`.
 *
 * Replica:
 *  - PageHeader (eyebrow + título + descripción + 2-3 acciones).
 *  - Tira de bodegas (chips horizontales).
 *  - 3 StatTile (Total / Bajo mínimo / Valorización).
 *  - FilterBar (search + select categoría + 2 checkboxes).
 *  - Mobile (<sm): card-stack con título + 3 fields + footer kebab.
 *  - Desktop (≥sm): tabla 7 cols (Insumo, Categoría, Existencias, Mín,
 *    Costo unit., Estado, Acciones).
 *
 * Ver FRONTEND_UI_GUIDELINES §10 (responsive tables) y §13 (loading).
 */
export function InventorySkeleton({ rows = 6, showWarehouseTabs = true, className }: InventorySkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando inventario" className={cn('space-y-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-24 rounded-full" />
                    <Skeleton className="h-9 w-56" />
                    <Skeleton className="h-4 w-full max-w-xl" />
                </div>
                <div className="flex flex-wrap gap-2">
                    <Skeleton className="h-9 w-28 rounded-md" />
                    <Skeleton className="h-9 w-28 rounded-md" />
                    <Skeleton className="h-9 w-32 rounded-md" />
                </div>
            </div>

            {/* Warehouse tabs */}
            {showWarehouseTabs && (
                <div className="flex flex-wrap items-center gap-2">
                    <Skeleton className="h-4 w-14" />
                    <Skeleton className="h-9 w-20 rounded-md" />
                    <Skeleton className="h-9 w-24 rounded-md" />
                    <Skeleton className="h-9 w-28 rounded-md" />
                </div>
            )}

            {/* StatTiles */}
            <div className="grid gap-3 sm:grid-cols-3">
                {[0, 1, 2].map((i) => (
                    <div key={i} className="border-border bg-card space-y-2 rounded-2xl border p-5 shadow-sm">
                        <Skeleton className="h-8 w-24" />
                        <Skeleton className="h-3 w-32" />
                    </div>
                ))}
            </div>

            {/* FilterBar */}
            <div className="border-border bg-card flex flex-col gap-3 rounded-2xl border p-3 shadow-sm sm:flex-row sm:items-center sm:p-4">
                <Skeleton className="h-9 w-full sm:max-w-xs" />
                <div className="flex flex-wrap items-center gap-3">
                    <Skeleton className="h-9 w-44 rounded-md" />
                    <Skeleton className="h-4 w-32" />
                    <Skeleton className="h-4 w-28" />
                </div>
            </div>

            {/* Mobile card-stack */}
            <ul className="space-y-3 sm:hidden">
                {Array.from({ length: rows }).map((_, i) => (
                    <li key={i} className="border-border bg-card space-y-3 rounded-2xl border p-4 shadow-sm">
                        <div className="space-y-1.5">
                            <Skeleton className="h-4 w-40" />
                            <Skeleton className="h-3 w-24" />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            {[0, 1, 2].map((j) => (
                                <div key={j} className="space-y-1">
                                    <Skeleton className="h-2.5 w-16" />
                                    <Skeleton className="h-3.5 w-20" />
                                </div>
                            ))}
                        </div>
                        <div className="border-border flex justify-end border-t pt-3">
                            <Skeleton className="h-8 w-24 rounded-md" />
                        </div>
                    </li>
                ))}
            </ul>

            {/* Desktop table */}
            <div className="border-border hidden overflow-hidden rounded-lg border sm:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-xs uppercase">
                        <tr>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-16" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-20" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-14" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-10" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-20" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-16" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-20" />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {Array.from({ length: rows }).map((_, i) => (
                            <tr key={i} className="border-border border-t">
                                <td className="px-4 py-3">
                                    <Skeleton className="h-4 w-32" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-4 w-20" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="ml-auto h-4 w-16" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="ml-auto h-4 w-12" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="ml-auto h-4 w-20" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-5 w-12 rounded-full" />
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex justify-end gap-1">
                                        <Skeleton className="h-7 w-7 rounded-md" />
                                        <Skeleton className="h-7 w-7 rounded-md" />
                                        <Skeleton className="h-7 w-7 rounded-md" />
                                        <Skeleton className="h-7 w-7 rounded-md" />
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
