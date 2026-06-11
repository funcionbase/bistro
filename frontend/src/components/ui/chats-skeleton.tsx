import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface ChatsSkeletonProps {
    /** Conversaciones del rail izquierdo. Default 6. */
    conversations?: number;
    className?: string;
}

/**
 * Skeleton fiel de `/chats` (operador WhatsApp).
 *
 * Replica:
 *  - Mobile (<md): solo la columna de conversaciones (search + lista). El
 *    panel de detalle aparece al seleccionar una conversación.
 *  - Desktop (≥md): rail 1/3 con search + lista + panel principal con
 *    header + mensajes + input.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §13 (loading).
 */
export function ChatsSkeleton({ conversations = 6, className }: ChatsSkeletonProps) {
    return (
        <div
            aria-busy="true"
            aria-label="Cargando conversaciones"
            className={cn(
                'flex h-[calc(100svh-4rem)] min-h-0 flex-1 flex-col gap-4 overflow-hidden p-2 sm:p-4 md:h-[calc(100svh-5rem)] md:flex-row',
                className,
            )}
        >
            {/* Rail izquierdo — conversaciones */}
            <div className="bg-muted/30 flex min-h-0 w-full flex-col overflow-hidden rounded-lg md:w-1/3">
                <div className="border-b p-2">
                    <Skeleton className="h-4 w-28" />
                </div>
                <div className="border-b p-2">
                    <Skeleton className="h-9 w-full" />
                </div>
                <div className="flex-1 space-y-2 overflow-y-auto p-2">
                    {Array.from({ length: conversations }).map((_, i) => (
                        <div
                            key={i}
                            className="border-border bg-card space-y-1.5 rounded-lg border p-3 shadow-sm"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <Skeleton className="h-4 w-2/3" />
                                <Skeleton className="h-3 w-12" />
                            </div>
                            <Skeleton className="h-3 w-full" />
                        </div>
                    ))}
                </div>
            </div>

            {/* Panel derecho — conversación */}
            <div className="bg-background hidden min-h-0 flex-1 flex-col overflow-hidden rounded-lg md:flex">
                <div className="flex items-center justify-between gap-2 border-b p-2">
                    <div className="flex items-center gap-2">
                        <Skeleton className="h-4 w-32" />
                        <Skeleton className="h-3 w-20" />
                    </div>
                    <div className="flex gap-2">
                        <Skeleton className="h-8 w-24" />
                        <Skeleton className="h-8 w-28" />
                    </div>
                </div>
                <div className="flex-1 space-y-3 overflow-y-auto p-4">
                    {[0, 1, 2, 3, 4].map((i) => (
                        <div
                            key={i}
                            className={cn('flex', i % 2 === 0 ? 'justify-start' : 'justify-end')}
                        >
                            <Skeleton
                                className={cn(
                                    'h-12 rounded-xl',
                                    i % 2 === 0 ? 'w-52' : 'w-44',
                                )}
                            />
                        </div>
                    ))}
                </div>
                <div className="border-t p-3">
                    <div className="flex items-center gap-2">
                        <Skeleton className="h-10 flex-1 rounded-lg" />
                        <Skeleton className="h-10 w-24 rounded-lg" />
                    </div>
                </div>
            </div>
        </div>
    );
}
