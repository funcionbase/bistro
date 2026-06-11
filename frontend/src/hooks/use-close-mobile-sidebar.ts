import { useSidebar } from '@/components/ui/sidebar';
import { useCallback } from 'react';

/**
 * En móvil/PWA el sidebar se renderiza como un `Sheet` superpuesto (ver
 * `components/ui/sidebar.tsx`). Al tocar una opción de navegación y cambiar de
 * pantalla, el overlay queda abierto tapando la vista a la que acabás de
 * llegar — el usuario tiene que cerrarlo a mano para ver el contenido.
 *
 * Este hook devuelve un handler para colgar en el `onClick` de los links de
 * navegación del sidebar: cierra el Sheet automáticamente tras navegar. En
 * desktop (`isMobile === false`) es un no-op — el sidebar fijo conserva su
 * estado expandido/colapsado.
 *
 * Debe usarse dentro del `SidebarProvider` (lo está todo lo que se renderiza
 * dentro de `<Sidebar>`).
 */
export function useCloseMobileSidebar(): () => void {
    const { isMobile, setOpenMobile } = useSidebar();
    return useCallback(() => {
        if (isMobile) {
            setOpenMobile(false);
        }
    }, [isMobile, setOpenMobile]);
}
