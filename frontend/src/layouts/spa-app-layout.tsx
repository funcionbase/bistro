import { ConsentBanner } from '@/components/consent-banner';
import { GlobalShortcuts } from '@/components/global-shortcuts';
import { RouteSkeleton } from '@/components/route-skeleton';
import { RouteProgress } from '@/components/ui/route-progress';
import { useBootstrap } from '@/hooks/use-bootstrap';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BusinessProvider } from '@/lib/business-context';
import { PageTitleProvider } from '@/lib/page-title-provider';
import { SpaSharedDataBridge } from '@/lib/shared-data';
import { LoaderCircle } from 'lucide-react';
import { Suspense, useEffect } from 'react';
import { Outlet } from 'react-router-dom';

/**
 * Layout autenticado del shell SPA (#220, Fase 3).
 *
 * Carga el contexto global vía useBootstrap() y, una vez resuelto, monta
 * el SpaSharedDataBridge + el layout de sidebar compartido (ya agnóstico
 * del transporte tras la migración de la cadena del layout en F3.2).
 *
 * Las rutas hijas se renderizan en el <Outlet/>.
 */

function FullScreenLoader() {
    return (
        <div className="bg-background flex min-h-dvh items-center justify-center">
            <LoaderCircle className="text-muted-foreground size-8 animate-spin" />
        </div>
    );
}

export function SpaAppLayout() {
    const bootstrap = useBootstrap();

    // Sesión perdida (401/403): el interceptor de apiFetch ya redirige; este
    // efecto cubre el caso de un error de red sostenido sin redirect.
    useEffect(() => {
        if (bootstrap.isError) {
            window.location.assign('/');
        }
    }, [bootstrap.isError]);

    if (bootstrap.isLoading || bootstrap.isError || !bootstrap.data) {
        return <FullScreenLoader />;
    }

    return (
        <SpaSharedDataBridge bootstrap={bootstrap.data}>
            <ConsentBanner />
            <BusinessProvider>
                <PageTitleProvider>
                    <AppSidebarLayout>
                        <RouteProgress />
                        <GlobalShortcuts />
                        <Suspense fallback={<RouteSkeleton />}>
                            <Outlet />
                        </Suspense>
                    </AppSidebarLayout>
                </PageTitleProvider>
            </BusinessProvider>
        </SpaSharedDataBridge>
    );
}
