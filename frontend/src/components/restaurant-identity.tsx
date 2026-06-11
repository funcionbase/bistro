import { SidebarMenu, SidebarMenuItem } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useInitials } from '@/hooks/use-initials';
import { useSharedData } from '@/lib/shared-data';
import { TOOLTIP_DELAY_MS } from '@/lib/shortcuts';
import { type Company } from '@/types';

function CompanyAvatar({ company }: { company: Company }) {
    const getInitials = useInitials();
    const initials = getInitials(company.name);

    if (company.logo_url) {
        return <img src={company.logo_url} alt={company.name} className="ring-border size-8 shrink-0 rounded-full object-cover ring-1" />;
    }

    return (
        <span className="bg-accent text-accent-foreground flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-bold select-none">
            {initials}
        </span>
    );
}

/**
 * Identidad de la empresa activa en el header del sidebar. Es display puro:
 * logo + nombre. El cambio de empresa NO se ofrece acá — vive en el footer
 * del sidebar como una acción dedicada (`NavUser` → "Cambiar empresa"), así
 * que este componente intencionalmente no expone ningún switcher.
 */
export function RestaurantIdentity() {
    const { activeCompany } = useSharedData();

    if (!activeCompany) {
        return null;
    }

    return (
        <SidebarMenu className="group-data-[collapsible=icon]:items-center">
            <SidebarMenuItem>
                <div className="flex min-w-0 items-center gap-2.5 px-2 py-2 transition-[padding,gap] duration-200 ease-linear group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:gap-0 group-data-[collapsible=icon]:p-0">
                    <CompanyAvatar company={activeCompany} />
                    {/*
                        Bloque texto (nombre + NIT): se oculta en colapsado con
                        `hidden` en vez de solo `opacity-0`. El texto interior
                        tiene `max-w-[140px] truncate` (no se encoge a 0 con
                        flex-shrink solo), así que dejarlo en el flow forzaba
                        a la fila a ser ~172px de ancho y `items-center` del
                        SidebarMenu empujaba el avatar fuera del carril del
                        sidebar (32px). Con `hidden` el contenedor sale del
                        flow y el avatar queda perfectamente centrado.
                    */}
                    <div className="flex min-w-0 flex-col gap-0.5 transition-[opacity,transform] duration-200 ease-linear group-data-[collapsible=icon]:hidden">
                        <TooltipProvider delayDuration={TOOLTIP_DELAY_MS}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="text-foreground max-w-[140px] cursor-default truncate text-sm leading-tight font-semibold">
                                        {activeCompany.name}
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent side="right">{activeCompany.name}</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        {activeCompany.nit && (
                            <span className="text-muted-foreground max-w-[140px] truncate font-mono text-[11px] leading-tight tabular-nums">
                                NIT {activeCompany.nit}
                            </span>
                        )}
                    </div>
                </div>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
