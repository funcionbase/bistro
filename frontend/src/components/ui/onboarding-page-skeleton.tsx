import { Skeleton } from '@/components/ui/skeleton';
import { SelectableTileGridSkeleton } from '@/components/ui/selectable-tile-skeleton';
import { cn } from '@/lib/utils';

type OnboardingLayout = 'form' | 'tiles' | 'status';

interface OnboardingPageSkeletonProps {
    /**
     * Layout esqueletizado:
     *  - `form`    : wizard step indicator + 3 inputs + boton (enrollment/*).
     *  - `tiles`   : 4 selectable tiles en grid (auth/company-selector,
     *                auth/branch-selector).
     *  - `status`  : tarjeta de estado compacta centrada (company/under-review).
     */
    layout?: OnboardingLayout;
    /** Si `true`, oculta el `HeroPanel` lateral (default `false`). */
    hidePanel?: boolean;
    /** Forwarded al grid de tiles cuando `layout='tiles'`. */
    tiles?: number;
    className?: string;
}

/**
 * Skeleton generico para las pantallas de onboarding y auth:
 *  - `enrollment/user`, `enrollment/company` (`layout='form'`).
 *  - `auth/company-selector`, `auth/branch-selector` (`layout='tiles'`).
 *  - `company/under-review` (`layout='status'`).
 *
 * Replica el shell editorial 2-col (logo + headline/contenido + panel lime
 * lateral) que comparten todas estas paginas. En mobile cae a stack vertical.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2b (hero 2-col), §10 (skeletons), §13
 * (loading): mientras el JWT/usuario carga, no dejamos la pagina en blanco.
 */
export function OnboardingPageSkeleton({
    layout = 'form',
    hidePanel = false,
    tiles = 4,
    className,
}: OnboardingPageSkeletonProps) {
    if (layout === 'status') {
        return (
            <div
                aria-busy="true"
                aria-label="Cargando estado de la cuenta"
                className={cn('bg-background flex min-h-dvh items-center justify-center px-4 py-8 md:p-8', className)}
            >
                <div className="w-full max-w-md space-y-6">
                    <div className="flex flex-col items-center gap-6 text-center">
                        <Skeleton className="h-16 w-16 rounded-full" />
                        <div className="w-full space-y-2">
                            <Skeleton className="mx-auto h-7 w-3/4" />
                            <Skeleton className="mx-auto h-3 w-32" />
                        </div>
                        <Skeleton className="h-24 w-full rounded-xl" />
                        <Skeleton className="h-3 w-40" />
                        <Skeleton className="h-10 w-full rounded-md" />
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div
            aria-busy="true"
            aria-label="Cargando"
            className={cn('bg-background flex min-h-dvh items-center justify-center px-4 py-8 md:p-8', className)}
        >
            <div className="w-full max-w-6xl">
                <div className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12 lg:gap-16">
                    {/* Columna izquierda: logo + headline + contenido */}
                    <div
                        className={cn(
                            'flex flex-col gap-6 sm:gap-8 md:gap-10',
                            hidePanel ? 'md:col-span-12' : 'md:col-span-7 lg:col-span-7',
                        )}
                    >
                        <Skeleton className="h-8 w-32 md:h-10 md:w-40" />

                        <div className="space-y-3 sm:space-y-4">
                            <Skeleton className="h-5 w-24 rounded-full" />
                            <Skeleton className="h-10 w-3/4 md:h-14 lg:h-16" />
                            <Skeleton className="h-10 w-2/3 md:h-14 lg:h-16" />
                            <Skeleton className="h-4 w-full max-w-md" />
                            <Skeleton className="h-4 w-2/3 max-w-md" />
                        </div>

                        {layout === 'form' && (
                            <div className="space-y-6">
                                {/* Wizard step indicator */}
                                <div className="flex items-center gap-3">
                                    <Skeleton className="h-8 w-8 rounded-full" />
                                    <Skeleton className="h-px w-10" />
                                    <Skeleton className="h-8 w-8 rounded-full" />
                                    <Skeleton className="h-px w-10" />
                                    <Skeleton className="h-8 w-8 rounded-full" />
                                </div>
                                {/* Inputs */}
                                <div className="space-y-4">
                                    {[0, 1, 2].map((i) => (
                                        <div key={i} className="space-y-2">
                                            <Skeleton className="h-4 w-24" />
                                            <Skeleton className="h-10 w-full rounded-md" />
                                        </div>
                                    ))}
                                </div>
                                <Skeleton className="h-11 w-full rounded-md sm:w-32" />
                            </div>
                        )}

                        {layout === 'tiles' && (
                            <SelectableTileGridSkeleton tiles={tiles} gridClassName="gap-4 sm:grid-cols-2" />
                        )}
                    </div>

                    {/* Columna derecha: hero panel lime */}
                    {!hidePanel && (
                        <aside className="bg-muted/60 hidden md:col-span-5 md:flex md:flex-col md:justify-between md:gap-8 md:rounded-3xl md:p-6 lg:col-span-5 lg:p-10">
                            <Skeleton className="h-5 w-32 rounded-full" />
                            <div className="space-y-6">
                                {[0, 1, 2].map((i) => (
                                    <div key={i} className="border-foreground/10 space-y-2 border-b pb-4 last:border-b-0 last:pb-0">
                                        <Skeleton className="h-3 w-20" />
                                        <Skeleton className="h-9 w-32" />
                                    </div>
                                ))}
                            </div>
                            <div className="space-y-2">
                                <Skeleton className="h-3 w-full" />
                                <Skeleton className="h-3 w-5/6" />
                                <Skeleton className="h-3 w-2/3" />
                            </div>
                        </aside>
                    )}
                </div>
            </div>
        </div>
    );
}
