import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface SuppliersListSkeletonProps {
    /** Filas a esqueletizar en mobile y desktop. Default 5. */
    rows?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/suppliers` (catálogo de proveedores).
 *
 * Replica:
 *  - PageHeader (eyebrow CATÁLOGO + título + 2 acciones).
 *  - 4 StatTile (Totales / Activos / Archivados / Plazo promedio).
 *  - FilterBar (search + checkbox archivados).
 *  - Mobile (<sm): card-stack con nombre + 4 fields + kebab.
 *  - Desktop (≥sm): tabla 6 cols (Nombre, Documento, Contacto, Teléfono,
 *    Plazo, Acciones).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §10 (responsive tables) y §13 (loading).
 */
export function SuppliersListSkeleton({ rows = 5, className }: SuppliersListSkeletonProps) {
    return (
        <div aria-busy="true" aria-label="Cargando proveedores" className={cn('space-y-6', className)}>
            {/* PageHeader */}
            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-5 w-24 rounded-full" />
                    <Skeleton className="h-9 w-56" />
                    <Skeleton className="h-4 w-full max-w-md" />
                </div>
                <div className="flex flex-col gap-2 sm:flex-row md:shrink-0">
                    <Skeleton className="h-9 w-full sm:w-28" />
                    <Skeleton className="h-9 w-full sm:w-40" />
                </div>
            </div>

            {/* 4 StatTile */}
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                {Array.from({ length: 4 }).map((_, i) => (
                    <div
                        key={i}
                        className="border-border bg-card space-y-2 rounded-2xl border p-5 shadow-sm"
                    >
                        <Skeleton className="h-8 w-16" />
                        <Skeleton className="h-3 w-24" />
                    </div>
                ))}
            </div>

            {/* FilterBar */}
            <div className="border-border bg-card flex flex-col gap-3 rounded-lg border p-3 shadow-sm sm:flex-row sm:items-center">
                <Skeleton className="h-9 w-full sm:w-72 sm:flex-1" />
                <Skeleton className="h-5 w-44" />
            </div>

            {/* Mobile card-stack */}
            <ul className="space-y-3 sm:hidden">
                {Array.from({ length: rows }).map((_, i) => (
                    <li
                        key={i}
                        className="border-border bg-card space-y-3 rounded-lg border p-4 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <Skeleton className="h-4 w-2/3" />
                            <Skeleton className="h-7 w-7 shrink-0 rounded-md" />
                        </div>
                        <div className="grid grid-cols-2 gap-x-3 gap-y-2">
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-14" />
                                <Skeleton className="h-3.5 w-24" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-14" />
                                <Skeleton className="h-3.5 w-20" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-14" />
                                <Skeleton className="h-3.5 w-24" />
                            </div>
                            <div className="space-y-1">
                                <Skeleton className="h-2.5 w-12" />
                                <Skeleton className="h-3.5 w-12" />
                            </div>
                        </div>
                    </li>
                ))}
            </ul>

            {/* Desktop table */}
            <div className="bg-card hidden overflow-hidden rounded-lg border shadow-sm sm:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-xs uppercase">
                        <tr>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-20" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-24" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-20" />
                            </th>
                            <th className="px-4 py-3 text-left">
                                <Skeleton className="h-3 w-16" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-12" />
                            </th>
                            <th className="px-4 py-3 text-right">
                                <Skeleton className="ml-auto h-3 w-16" />
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
                                    <Skeleton className="h-4 w-28" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-4 w-24" />
                                    <Skeleton className="mt-1 h-3 w-32" />
                                </td>
                                <td className="px-4 py-3">
                                    <Skeleton className="h-4 w-20" />
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <Skeleton className="ml-auto h-4 w-10" />
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <div className="inline-flex gap-1">
                                        <Skeleton className="h-8 w-8 rounded-md" />
                                        <Skeleton className="h-8 w-8 rounded-md" />
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
