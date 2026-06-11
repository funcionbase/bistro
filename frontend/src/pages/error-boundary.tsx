import { ErrorScreen } from '@/components/error-screen';
import { Button } from '@/components/ui/button';
import { attemptChunkRecovery, isChunkLoadError } from '@/lib/chunk-recovery';
import { useEffect } from 'react';
import { useRouteError } from 'react-router-dom';

/**
 * Página de error de runtime del frontend SPA — `errorElement` del router.
 *
 * React Router la renderiza cuando una ruta lanza un error no controlado:
 * fallo al cargar un chunk lazy tras un deploy, excepción en render, etc.
 * Usa el shell `ErrorScreen` (patrón hero 2-col del DS) para quedar
 * consistente con `errors/500.blade.php` del backend.
 *
 * Las acciones fuerzan navegación dura (`window.location`): tras un crash
 * el árbol de React puede quedar en estado inconsistente, así que un reload
 * limpio es más confiable que una navegación SPA.
 *
 * Si el error es un fallo de chunk dinámico (chunk obsoleto por deploy),
 * intentamos auto-recovery con reload — el usuario nunca debería ver esta
 * pantalla por algo recoverable. Solo si ya hubo un intento previo de
 * recovery en esta sesión, mostramos el error real.
 */
export default function ErrorBoundary() {
    const error = useRouteError();
    const detail = error instanceof Error ? error.message : null;

    useEffect(() => {
        if (isChunkLoadError(error)) {
            attemptChunkRecovery();
        }
    }, [error]);

    return (
        <ErrorScreen
            documentTitle="Error de aplicación"
            eyebrow="Error 500 · algo se rompió de nuestro lado"
            title={
                <>
                    Un cable suelto
                    <br />
                    de este lado.
                </>
            }
            description="Algo nuestro falló al cargar esta sección. No es tu navegador ni tu internet. Recarga; si vuelve a pasar, abajo te dejamos por dónde seguir mientras lo arreglamos."
            actions={
                <>
                    <Button onClick={() => window.location.reload()}>Recargar la app</Button>
                    <Button
                        variant="outline"
                        onClick={() => {
                            window.location.href = '/';
                        }}
                    >
                        Volver al inicio
                    </Button>
                </>
            }
            footerLabel="Error de aplicación"
            panelEyebrow="¿Sigue fallando?"
            panelBody={
                <>
                    <p>
                        Si recargar no resuelve, cierra sesión y vuelve a entrar. Si el error persiste, escríbele a soporte con la hora en que pasó —
                        nosotros nos encargamos del cable.
                    </p>
                    {import.meta.env.DEV && detail && (
                        <pre className="bg-muted text-muted-foreground overflow-x-auto rounded-lg p-3 text-xs">{detail}</pre>
                    )}
                </>
            }
        />
    );
}
