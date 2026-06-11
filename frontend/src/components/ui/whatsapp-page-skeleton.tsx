import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface WhatsappPageSkeletonProps {
    /** Si `true`, esqueletiza también la card de "Conectado" (status detail).
     * Si `false` (default), esqueletiza la grilla de 2 cards "Tengo número /
     * flexyflow me provee". */
    connected?: boolean;
    className?: string;
}

/**
 * Esqueleto fiel de `/company/whatsapp`. Reemplaza el spinner inline que
 * había antes ("Cargando estado de WhatsApp...") para que en conexiones
 * lentas la página no se quede en blanco con un texto plano.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo) y §11 (skeletons).
 */
export function WhatsappPageSkeleton({ connected = false, className }: WhatsappPageSkeletonProps) {
    return (
        <div className={cn('space-y-6', className)} aria-busy="true" aria-label="Cargando estado de WhatsApp">
            {/* Header */}
            <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <Skeleton className="size-9 rounded-lg" />
                        <Skeleton className="h-7 w-32" />
                    </div>
                    <Skeleton className="h-4 w-full max-w-md" />
                    <Skeleton className="h-3 w-48" />
                </div>
                <Skeleton className="h-6 w-28 rounded-full" />
            </header>

            {/* Bot en desarrollo alert */}
            <Skeleton className="h-20 w-full rounded-md" />

            {/* Connected vs Disconnected detail */}
            {connected ? (
                <Card>
                    <CardHeader className="space-y-2">
                        <Skeleton className="h-5 w-44" />
                        <Skeleton className="h-3 w-32" />
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        {[0, 1, 2, 3].map((i) => (
                            <div key={i} className="space-y-1.5">
                                <Skeleton className="h-3 w-20" />
                                <Skeleton className="h-4 w-32" />
                            </div>
                        ))}
                    </CardContent>
                    <CardContent className="flex flex-wrap gap-2 pt-0">
                        <Skeleton className="h-9 w-28 rounded-md" />
                        <Skeleton className="h-9 w-32 rounded-md" />
                        <Skeleton className="h-9 w-28 rounded-md" />
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {[0, 1].map((i) => (
                        <Card key={i} className="flex flex-col">
                            <CardHeader className="space-y-2">
                                <Skeleton className="size-10 rounded-lg" />
                                <Skeleton className="h-5 w-40" />
                                <Skeleton className="h-3 w-full max-w-xs" />
                            </CardHeader>
                            <CardContent className="flex-1 space-y-2">
                                <Skeleton className="h-3 w-3/4" />
                                <Skeleton className="h-3 w-2/3" />
                                <Skeleton className="h-3 w-3/5" />
                            </CardContent>
                            <CardContent className="pt-0">
                                <Skeleton className="h-9 w-full sm:w-32 rounded-md" />
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}

            {/* Preferences block: privacy + bot */}
            <div className="space-y-3">
                <div className="space-y-1.5">
                    <Skeleton className="h-5 w-32" />
                    <Skeleton className="h-3 w-64" />
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    {[0, 1].map((i) => (
                        <Card key={i}>
                            <CardHeader className="space-y-2">
                                <Skeleton className="h-5 w-28" />
                                <Skeleton className="h-3 w-44" />
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <Skeleton className="h-14 w-full rounded-lg" />
                                <Skeleton className="h-20 w-full rounded-lg" />
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </div>
    );
}
