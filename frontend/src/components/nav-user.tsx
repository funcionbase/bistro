import { AppLink } from '@/components/app-link';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarSeparator } from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { useCloseMobileSidebar } from '@/hooks/use-close-mobile-sidebar';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { route } from '@/lib/route-compat';
import { useSharedData } from '@/lib/shared-data';
import { useLogout } from '@/lib/use-logout';
import { ArrowLeftRight, LogOut, User } from 'lucide-react';
import { useCallback } from 'react';

export function NavUser() {
    const { auth } = useSharedData();
    const cleanupPointerEvents = useMobileNavigation();
    const closeMobileSidebar = useCloseMobileSidebar();
    const logout = useLogout();

    // Al navegar desde el sidebar en móvil hay que (1) limpiar el
    // `pointer-events` que deja Radix y (2) cerrar el Sheet para no tapar la
    // pantalla destino. En desktop solo aplica lo primero.
    const cleanup = useCallback(() => {
        cleanupPointerEvents();
        closeMobileSidebar();
    }, [cleanupPointerEvents, closeMobileSidebar]);

    return (
        <>
            {/*
                Bloque de identidad del usuario (avatar + nombre + email): se
                oculta cuando el sidebar está colapsado a icon-only. En ese
                estado no hay ancho para nombre/email, y dejarlo visible
                obliga al avatar a alinearse a la izquierda — se ve roto. Los
                ítems "Mi perfil / Cambiar empresa / Cerrar sesión" de abajo
                ya cubren las acciones, así que esconder el bloque es la
                opción más limpia.
            */}
            <div className="flex items-center gap-2 px-2 py-1.5 group-data-[collapsible=icon]:hidden">
                <UserInfo user={auth.user} showEmail />
            </div>
            <SidebarSeparator className="group-data-[collapsible=icon]:hidden" />
            <SidebarMenu className="group-data-[collapsible=icon]:items-center">
                <SidebarMenuItem>
                    <SidebarMenuButton asChild tooltip="Mi perfil">
                        <AppLink href={route('me')} prefetch onClick={cleanup}>
                            <User />
                            <span>Mi perfil</span>
                        </AppLink>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton asChild tooltip="Cambiar empresa">
                        <AppLink href={route('auth.company-selector')} prefetch onClick={cleanup}>
                            <ArrowLeftRight />
                            <span>Cambiar empresa</span>
                        </AppLink>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        tooltip="Cerrar sesión"
                        onClick={() => {
                            cleanup();
                            logout();
                        }}
                    >
                        <LogOut />
                        <span>Cerrar sesión</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </>
    );
}
