import { AppLink } from '@/components/app-link';
import { ErrorScreen } from '@/components/error-screen';
import { Button } from '@/components/ui/button';

/**
 * Página 404 del frontend SPA — catch-all `*` del router.
 *
 * El backend Laravel solo sirve la API: cualquier ruta desconocida se
 * resuelve enteramente en el cliente. Usa el shell `ErrorScreen` (patrón
 * hero 2-col del DS) para quedar consistente con `errors/404.blade.php`.
 *
 * Volver al inicio usa `<AppLink>` (React Router): la 404 es solo una ruta
 * no emparejada, el router sigue sano y se puede navegar sin recarga.
 */
export default function NotFound() {
    return (
        <ErrorScreen
            documentTitle="Página no encontrada"
            eyebrow="Error 404 · intento de navegación interceptado"
            title={
                <>
                    Por acá
                    <br />
                    no hay nada.
                </>
            }
            description="El enlace cambió, la dirección quedó mal escrita o la página se mudó de lugar. Nada grave — abajo te dejamos por dónde seguir."
            actions={
                <Button asChild>
                    <AppLink href="/">Volver al inicio</AppLink>
                </Button>
            }
            footerLabel="Error 404"
            panelEyebrow="¿Buscabas algo?"
            panelBody={
                <p>
                    Si llegaste desde un enlace viejo, vuelve al inicio y navega desde el menú principal. Algunas secciones cambiaron de ruta con el
                    rediseño.
                </p>
            }
            panelFooter="El resto del panel sigue operando normal — solo este recurso no existe."
        />
    );
}
