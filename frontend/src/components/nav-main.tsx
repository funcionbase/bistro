import { AppLink } from '@/components/app-link';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type BusinessCapabilities, useBusinessContext } from '@/lib/business-context';
import { useCurrentUrl, useSharedData } from '@/lib/shared-data';
import { APP_SHORTCUTS, type AppShortcut } from '@/lib/shortcuts';
import { type NavItem } from '@/types';
import { useCloseMobileSidebar } from '@/hooks/use-close-mobile-sidebar';
import { ChevronRight } from 'lucide-react';
import { ReactNode, useEffect, useMemo, useState } from 'react';

function canAccess(item: NavItem, permissions: string[], isSystem: boolean): boolean {
    if (!item.permission) return true;
    if (isSystem) return true;
    return permissions.includes(item.permission);
}

/**
 * Filtra recursivamente los items que tengan `businessCapability` activo en la
 * sede actual. Items sin `businessCapability` pasan tal cual. Grupos que se
 * quedan sin children visibles tras filtrar también desaparecen (no tiene
 * sentido mostrar un padre que abre un menú vacío).
 *
 * Si `capabilities` aún no se cargó (null), no filtramos — preferimos mostrar
 * temporal a esconder por un fetch que aún no resolvió.
 */
function filterByCapabilities(items: NavItem[], capabilities: BusinessCapabilities | null): NavItem[] {
    if (capabilities === null) return items;
    return items.flatMap((item) => {
        if (item.businessCapability && !capabilities[item.businessCapability]) {
            return [];
        }
        if (item.children) {
            const filteredChildren = filterByCapabilities(item.children, capabilities);
            if (filteredChildren.length === 0 && !item.url) {
                return [];
            }
            return [{ ...item, children: filteredChildren }];
        }
        return [item];
    });
}

/**
 * #268 — Única fuente de verdad de visibilidad por RBAC del sidebar.
 *
 * Oculta por completo los items para los que el usuario no tiene permiso.
 * Antes se renderizaban tachados (line-through + opacity-50), lo que ensuciaba
 * la UI; ahora simplemente no aparecen. Los componentes de render
 * (`NavMain`, `CollapsibleNavGroup`, `CollapsedFlyoutGroup`, etc.) asumen que
 * el árbol que reciben YA pasó por este filtro: no vuelven a chequear permisos,
 * con lo que no queda ninguna rama "denegada" que dibujar.
 *
 * Reglas:
 * - Items `comingSoon` se conservan — su estado "Pronto disponible" es
 *   intencional, no un bloqueo por permisos.
 * - Un grupo con `permission` propio que el usuario no tiene desaparece entero.
 * - Un grupo que se queda sin children visibles (y sin URL propia) desaparece.
 * - El owner (`is_system`) bypasea todo vía `canAccess`.
 */
function filterByPermissions(items: NavItem[], permissions: string[], isSystem: boolean): NavItem[] {
    return items.flatMap((item) => {
        if (item.children) {
            const filteredChildren = filterByPermissions(item.children, permissions, isSystem);
            if (item.permission && !isSystem && !permissions.includes(item.permission)) {
                return [];
            }
            if (filteredChildren.length === 0 && !item.url) {
                return [];
            }
            return [{ ...item, children: filteredChildren }];
        }
        if (item.comingSoon) {
            return [item];
        }
        if (!canAccess(item, permissions, isSystem)) {
            return [];
        }
        return [item];
    });
}

function pathOf(url: string | undefined): string {
    if (!url) return '';
    try {
        return new URL(url, 'http://x').pathname;
    } catch {
        return url.split('?')[0];
    }
}

function urlMatches(itemUrl: string | undefined, currentUrl: string): boolean {
    if (!itemUrl) return false;
    return pathOf(itemUrl) === pathOf(currentUrl);
}

/**
 * Match recursivo: el item o cualquiera de sus descendientes apunta al URL
 * actual. Necesario para que un padre con sub-submenús (p.ej. "Mi Empresa" →
 * "Configuraciones" → "General") se auto-expanda cuando el usuario está en
 * un nieto. Sin esto, sólo se abrían los padres cuyo hijo DIRECTO matcheara
 * la URL — y los grupos con sub-grupos (Configuraciones) quedaban cerrados.
 */
function descendantMatches(item: NavItem, currentUrl: string): boolean {
    if (urlMatches(item.url, currentUrl)) return true;
    if (item.children) {
        return item.children.some((child) => descendantMatches(child, currentUrl));
    }
    return false;
}

function findShortcutFor(itemUrl: string | undefined): AppShortcut | undefined {
    if (!itemUrl) return undefined;
    const path = pathOf(itemUrl);
    return APP_SHORTCUTS.find((s) => s.route === path);
}

/**
 * Envuelve un nav item con un tooltip lateral que muestra descripción y atajo
 * de teclado (si existe). El delay lo hereda del TooltipProvider del SidebarProvider.
 */
function NavItemTooltip({ title, shortcut, children }: { title: string; shortcut?: AppShortcut; children: ReactNode }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>{children}</TooltipTrigger>
            <TooltipContent side="right" align="center" className="flex items-center gap-3">
                <span>{title}</span>
                {shortcut && (
                    <span className="inline-flex items-center gap-0.5">
                        {shortcut.keys.map((k, idx) => (
                            <span key={`${k}-${idx}`} className="inline-flex items-center">
                                {idx > 0 && <span className="px-0.5 opacity-60">{shortcut.chord ? '+' : ''}</span>}
                                <kbd className="rounded border border-gray-500/40 bg-gray-700/40 px-1.5 py-0.5 font-mono text-[10px] uppercase">
                                    {k}
                                </kbd>
                            </span>
                        ))}
                    </span>
                )}
            </TooltipContent>
        </Tooltip>
    );
}

function ComingSoonTooltip({ children }: { children: ReactNode }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>{children}</TooltipTrigger>
            <TooltipContent side="right" align="center">
                <span>Pronto disponible</span>
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * Render de un grupo del sidebar. `label` se renderiza como
 * `SidebarGroupLabel` (uppercase tracking del DS); si se omite, el grupo
 * sale sin cabecera (utilizado para items "sueltos" al tope).
 *
 * El sidebar global se compone de varios `<NavMain>` consecutivos —
 * cada uno representa una sección semántica (Día a día, Operaciones, etc.)
 * con su propia política de permisos por hijo.
 */
export function NavMain({ items = [], label }: { items: NavItem[]; label?: string }) {
    const sharedData = useSharedData();
    const currentUrl = useCurrentUrl();
    const businessContext = useBusinessContext();
    const permissions = useMemo(() => sharedData.permissions ?? [], [sharedData.permissions]);
    const isSystem = sharedData.role?.is_system ?? false;
    const closeMobile = useCloseMobileSidebar();

    // #237 — filtro por capability del vertical de la sede activa. Si el
    // contexto aún no carga (null), mostramos todo para no parpadear; cuando
    // resuelve, los items cuyo `businessCapability` no aplique desaparecen.
    // #268 — encima, filtramos por RBAC: lo que el usuario no puede ver,
    // desaparece (no se tacha). A partir de acá el árbol está limpio y los
    // componentes de render no vuelven a chequear permisos.
    const visibleItems = useMemo(() => {
        const byCapability = filterByCapabilities(items, businessContext?.capabilities ?? null);
        return filterByPermissions(byCapability, permissions, isSystem);
    }, [items, businessContext?.capabilities, permissions, isSystem]);

    // Si TODO el grupo quedó vacío tras filtrar (ej. "Operaciones" en
    // `dark_store` que no tiene inventario), no renderizamos el header del
    // grupo — evita la línea "Operaciones" suelta sin nada debajo.
    if (visibleItems.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
            <SidebarMenu className="group-data-[collapsible=icon]:items-center">
                {visibleItems.map((item) =>
                    item.comingSoon ? (
                        <SidebarMenuItem key={item.title}>
                            <ComingSoonTooltip>
                                <SidebarMenuButton asChild>
                                    <span className="flex cursor-not-allowed items-center gap-2 opacity-50">
                                        {item.icon && <item.icon />}
                                        <span className="line-through">{item.title}</span>
                                    </span>
                                </SidebarMenuButton>
                            </ComingSoonTooltip>
                        </SidebarMenuItem>
                    ) : item.children ? (
                        <CollapsibleNavGroup key={item.title} item={item} currentUrl={currentUrl} />
                    ) : (
                        <SidebarMenuItem key={item.title}>
                            <NavItemTooltip title={item.title} shortcut={findShortcutFor(item.url)}>
                                <SidebarMenuButton asChild isActive={urlMatches(item.url, currentUrl)}>
                                    <AppLink href={item.url ?? '#'} prefetch onClick={closeMobile}>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </AppLink>
                                </SidebarMenuButton>
                            </NavItemTooltip>
                        </SidebarMenuItem>
                    ),
                )}
            </SidebarMenu>
        </SidebarGroup>
    );
}

/**
 * Grupo colapsable de 2º nivel. Recibe un `item` cuyo árbol YA fue filtrado
 * por `filterByPermissions` en `NavMain`, por lo que todos sus children
 * visibles son accesibles — no hay rama "denegada" que dibujar.
 */
function CollapsibleNavGroup({ item, currentUrl }: { item: NavItem; currentUrl: string }) {
    const containsActive = item.children?.some((child) => descendantMatches(child, currentUrl)) ?? false;
    const [open, setOpen] = useState(containsActive);
    const { state, isMobile } = useSidebar();
    const isCollapsedIcon = state === 'collapsed' && !isMobile;
    const closeMobile = useCloseMobileSidebar();

    useEffect(() => {
        if (containsActive) setOpen(true);
    }, [containsActive]);

    if (isCollapsedIcon) {
        return <CollapsedFlyoutGroup item={item} currentUrl={currentUrl} containsActive={containsActive} />;
    }

    return (
        <Collapsible asChild open={open} onOpenChange={setOpen} className="group/collapsible">
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton tooltip={item.title} isActive={containsActive}>
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                        <ChevronRight className="ml-auto h-4 w-4 transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {item.children!.map((sub) =>
                            sub.children && sub.children.length > 0 ? (
                                <NestedCollapsibleNavGroup key={sub.title} item={sub} currentUrl={currentUrl} />
                            ) : (
                                <SidebarMenuSubItem key={sub.title}>
                                    {sub.comingSoon ? (
                                        <ComingSoonTooltip>
                                            <SidebarMenuSubButton asChild>
                                                <span className="flex cursor-not-allowed items-center gap-2 opacity-50">
                                                    {sub.icon && <sub.icon />}
                                                    <span className="line-through">{sub.title}</span>
                                                </span>
                                            </SidebarMenuSubButton>
                                        </ComingSoonTooltip>
                                    ) : (
                                        <NavItemTooltip title={sub.title} shortcut={findShortcutFor(sub.url)}>
                                            <SidebarMenuSubButton asChild isActive={urlMatches(sub.url, currentUrl)}>
                                                <AppLink href={sub.url ?? '#'} prefetch onClick={closeMobile}>
                                                    {sub.icon && <sub.icon />}
                                                    <span>{sub.title}</span>
                                                </AppLink>
                                            </SidebarMenuSubButton>
                                        </NavItemTooltip>
                                    )}
                                </SidebarMenuSubItem>
                            ),
                        )}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

/**
 * Tercer nivel de la navegacion (sub-sub-menu): permite agrupar items dentro de
 * un padre colapsable que ya esta dentro de otro grupo. Usado para organizar
 * canales (WhatsApp, Instagram, Facebook) bajo "Configuraciones".
 *
 * Igual que `CollapsibleNavGroup`, recibe un árbol ya filtrado por RBAC.
 */
function NestedCollapsibleNavGroup({ item, currentUrl }: { item: NavItem; currentUrl: string }) {
    const containsActive = item.children?.some((child) => descendantMatches(child, currentUrl)) ?? false;
    const [open, setOpen] = useState(containsActive);
    const closeMobile = useCloseMobileSidebar();

    useEffect(() => {
        if (containsActive) setOpen(true);
    }, [containsActive]);

    return (
        <Collapsible asChild open={open} onOpenChange={setOpen} className="group/nested">
            <SidebarMenuSubItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuSubButton isActive={containsActive}>
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                        <ChevronRight className="ml-auto h-3.5 w-3.5 transition-transform duration-200 group-data-[state=open]/nested:rotate-90" />
                    </SidebarMenuSubButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub className="ml-2 border-l-0 pl-3">
                        {item.children!.map((leaf) => (
                            <SidebarMenuSubItem key={leaf.title}>
                                {leaf.comingSoon ? (
                                    <ComingSoonTooltip>
                                        <SidebarMenuSubButton asChild>
                                            <span className="flex cursor-not-allowed items-center gap-2 opacity-50">
                                                {leaf.icon && <leaf.icon />}
                                                <span className="line-through">{leaf.title}</span>
                                            </span>
                                        </SidebarMenuSubButton>
                                    </ComingSoonTooltip>
                                ) : (
                                    <NavItemTooltip title={leaf.title} shortcut={findShortcutFor(leaf.url)}>
                                        <SidebarMenuSubButton asChild isActive={urlMatches(leaf.url, currentUrl)}>
                                            <AppLink href={leaf.url ?? '#'} prefetch onClick={closeMobile}>
                                                {leaf.icon && <leaf.icon />}
                                                <span>{leaf.title}</span>
                                            </AppLink>
                                        </SidebarMenuSubButton>
                                    </NavItemTooltip>
                                )}
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuSubItem>
        </Collapsible>
    );
}

/**
 * Render del grupo cuando el sidebar está colapsado al ancho icon-only.
 *
 * En ese estado el `Collapsible` inline pierde su contenido (los hijos
 * tienen `group-data-[collapsible=icon]:hidden` heredado del shadcn
 * sidebar), así que el click en el icon parecía "muerto". Aquí
 * reemplazamos el Collapsible por un `DropdownMenu` lateral con
 * navegación jerárquica:
 *
 * - Hijos sin sub-children (ej. Catálogo → Menú): items planos.
 * - Hijos con sub-children (ej. Operaciones → Inventario): se
 *   renderizan como `DropdownMenuSub` con su propio submenu lateral
 *   que se abre al hover/click. Mantiene la jerarquía visual del
 *   árbol expandido sin aplanar.
 *
 * #268 — el árbol ya viene filtrado por RBAC desde `NavMain`; aquí sólo se
 * excluyen los `comingSoon` (en modo colapsado no tiene sentido un flyout
 * con items "Pronto disponible" no clickeables). Si tras eso el grupo queda
 * sin hijos navegables, no se renderiza.
 */
function CollapsedFlyoutGroup({ item, currentUrl, containsActive }: { item: NavItem; currentUrl: string; containsActive: boolean }) {
    const visibleChildren = (item.children ?? []).filter((child) => {
        if (child.comingSoon) return false;
        if (child.children && child.children.length > 0) {
            return child.children.some((leaf) => !leaf.comingSoon);
        }
        return true;
    });

    if (visibleChildren.length === 0) {
        return null;
    }

    return (
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <SidebarMenuButton tooltip={item.title} isActive={containsActive}>
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent side="right" align="start" sideOffset={8} className="min-w-[12rem]">
                    {visibleChildren.map((child, idx) => {
                        const isLastWithSiblings = idx < visibleChildren.length - 1;
                        if (child.children && child.children.length > 0) {
                            return <FlyoutSubGroup key={child.title} item={child} currentUrl={currentUrl} addSeparatorAfter={isLastWithSiblings} />;
                        }
                        return <FlyoutLeafItem key={child.title} item={child} currentUrl={currentUrl} />;
                    })}
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    );
}

function FlyoutLeafItem({ item, currentUrl }: { item: NavItem; currentUrl: string }) {
    return (
        <DropdownMenuItem asChild className={urlMatches(item.url, currentUrl) ? 'bg-sidebar-accent text-sidebar-accent-foreground' : undefined}>
            <AppLink href={item.url ?? '#'} prefetch className="cursor-pointer">
                {item.icon && <item.icon className="h-4 w-4" />}
                <span>{item.title}</span>
            </AppLink>
        </DropdownMenuItem>
    );
}

function FlyoutSubGroup({ item, currentUrl, addSeparatorAfter }: { item: NavItem; currentUrl: string; addSeparatorAfter: boolean }) {
    const visibleLeaves = (item.children ?? []).filter((leaf) => !leaf.comingSoon);
    const subContainsActive = visibleLeaves.some((leaf) => urlMatches(leaf.url, currentUrl));

    return (
        <>
            <DropdownMenuSub>
                <DropdownMenuSubTrigger className={subContainsActive ? 'bg-sidebar-accent/60 text-sidebar-accent-foreground' : undefined}>
                    {item.icon && <item.icon className="h-4 w-4" />}
                    <span>{item.title}</span>
                </DropdownMenuSubTrigger>
                <DropdownMenuPortal>
                    <DropdownMenuSubContent className="min-w-[12rem]">
                        {visibleLeaves.map((leaf) => (
                            <FlyoutLeafItem key={leaf.title} item={leaf} currentUrl={currentUrl} />
                        ))}
                    </DropdownMenuSubContent>
                </DropdownMenuPortal>
            </DropdownMenuSub>
            {addSeparatorAfter && <DropdownMenuSeparator />}
        </>
    );
}
