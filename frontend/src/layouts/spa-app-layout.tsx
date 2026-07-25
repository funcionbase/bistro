import { ConsentBanner } from '@/components/consent-banner';
import { ErrorScreen } from '@/components/error-screen';
import { GlobalShortcuts } from '@/components/global-shortcuts';
import UpdateAvailableToast from '@/components/pwa/update-available-toast';
import { RouteSkeleton } from '@/components/route-skeleton';
import { Button } from '@/components/ui/button';
import { RouteProgress } from '@/components/ui/route-progress';
import { useBootstrap } from '@/hooks/use-bootstrap';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { ApiError } from '@/lib/api-client';
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

    // Solo los errores de AUTH (401/403 del backend) expulsan a la landing.
    // Antes CUALQUIER error (incluida la falta de red) hacía assign('/'):
    // recargar /caja offline sin snapshot expulsaba al cajero del panel.
    const isAuthError = bootstrap.error instanceof ApiError && (bootstrap.error.status === 401 || bootstrap.error.status === 403);

    useEffect(() => {
        if (bootstrap.isError && isAuthError) {
            window.location.assign('/');
        }
    }, [bootstrap.isError, isAuthError]);

    // Error de red sin snapshot offline (use-bootstrap ya intentó el cache):
    // estado offline con reintento en vez de redirect.
    if (bootstrap.isError && !isAuthError) {
        return (
            <ErrorScreen
                documentTitle="Sin conexión"
                eyebrow="Sin conexión"
                title={
                    <>
                        No pudimos cargar
                        <br />
                        tu sesión
                    </>
                }
                description="No hay conexión con el servidor y este dispositivo no tiene datos guardados para trabajar sin red."
                actions={
                    <Button onClick={() => void bootstrap.refetch()} disabled={bootstrap.isFetching}>
                        {bootstrap.isFetching ? 'Reintentando…' : 'Reintentar'}
                    </Button>
                }
                footerLabel="Sin conexión"
                panelEyebrow="Qué hacer"
                panelBody={<p>Revisa tu conexión Wi-Fi o de datos y presiona «Reintentar». La sesión se retoma sola apenas vuelva la red.</p>}
            />
        );
    }

    if (bootstrap.isLoading || bootstrap.isError || !bootstrap.data) {
        return <FullScreenLoader />;
    }

    return (
        <SpaSharedDataBridge bootstrap={bootstrap.data}>
            <ConsentBanner />
            <UpdateAvailableToast />
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
