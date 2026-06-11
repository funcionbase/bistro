import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface SettingsFormSkeletonProps {
    /**
     * Cantidad de campos a esqueletizar. Default 3 (caso común:
     * profile = nombre + cédula + email; password = current + new + confirm).
     */
    fields?: number;
    /**
     * Si true, esqueletiza también un bloque grande destructivo abajo —
     * pensado para la página `/settings/profile` que tiene `<DeleteUser />`
     * al final del form.
     */
    withDestructiveBlock?: boolean;
    className?: string;
}

/**
 * Esqueleto reusable para forms de settings de cuenta (`/settings/profile`,
 * `/settings/password`, futuros forms). Replica la cabecera (HeadingSmall
 * = título + descripción) + N pares label/input + botón submit.
 *
 * Pensado para:
 *  - Estados de transición entre sub-páginas de settings (Inertia visit
 *    en vuelo en conexiones lentas).
 *  - Futuros forms con deferred props.
 *  - SSR de carga inicial mientras hidrata.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §10 (Skeletons).
 */
export function SettingsFormSkeleton({ fields = 3, withDestructiveBlock = false, className }: SettingsFormSkeletonProps) {
    return (
        <div
            aria-busy="true"
            aria-label="Cargando configuración"
            className={cn('space-y-6', className)}
        >
            <header className="space-y-1.5">
                <Skeleton className="h-5 w-44" />
                <Skeleton className="h-4 w-64" />
            </header>
            <div className="space-y-6">
                {Array.from({ length: fields }).map((_, i) => (
                    <div key={i} className="grid gap-2">
                        <Skeleton className="h-4 w-24" />
                        <Skeleton className="h-10 w-full rounded-md" />
                    </div>
                ))}
                <Skeleton className="h-10 w-32 rounded-md" />
            </div>
            {withDestructiveBlock && (
                <div className="border-destructive/30 bg-destructive/5 space-y-4 rounded-lg border p-4">
                    <div className="space-y-1.5">
                        <Skeleton className="h-4 w-32" />
                        <Skeleton className="h-3 w-56" />
                    </div>
                    <Skeleton className="h-10 w-32 rounded-md" />
                </div>
            )}
        </div>
    );
}
