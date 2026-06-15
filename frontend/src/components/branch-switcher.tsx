import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuItem } from '@/components/ui/sidebar';
import { useActiveBranch } from '@/hooks/use-active-branch';
import { useIsAnyDirty } from '@/hooks/use-dirty-state';
import { apiFetch } from '@/lib/api';
import { reloadContext } from '@/lib/navigate-compat';
import { queryClient } from '@/lib/query-client';
import { route } from '@/lib/route-compat';
import { useSharedData } from '@/lib/shared-data';
import { cn } from '@/lib/utils';
import { ChevronDown, LoaderCircle, MapPin, Plus, Star } from 'lucide-react';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';

const LAST_BRANCH_KEY = 'flexyflow.last_branch_id';

type SwitchBlocker = { kind: 'cash_open'; openSessionId: string | null; branchId: string } | { kind: 'dirty_state'; branchId: string };

/**
 * Switcher de sede activa. Aparece en el sidebar debajo de la identidad de
 * empresa cuando el usuario tiene más de una sede accesible. También expone
 * "+ Nueva sede" si tiene permiso `branches.manage`.
 *
 * Bloqueadores antes de hacer el switch (#192 Fase 3):
 *  1. Trabajo sin guardar (`useIsAnyDirty`): muestra ConfirmDialog pidiendo
 *     confirmar que se abandonarán los cambios. Si confirma → switch.
 *  2. Caja abierta en la sede actual (backend devuelve
 *     `BRANCH_SWITCH_BLOCKED_CASH_OPEN`): muestra ConfirmDialog con
 *     CTA "Ir a cierre de caja". Salvo permiso
 *     `cash_register.bypass_switch_lock` (owner-only por default).
 */
export function BranchSwitcher() {
    const navigate = useNavigate();
    const { activeBranch, branches } = useActiveBranch();
    const { permissions = [], activeCompany } = useSharedData();
    const [switching, setSwitching] = useState(false);
    const [blocker, setBlocker] = useState<SwitchBlocker | null>(null);
    const anyDirty = useIsAnyDirty();

    if (!activeBranch || branches.length === 0) {
        return null;
    }

    const canManage = permissions.includes('branches.manage');
    const hasMultiple = branches.length > 1;

    async function performSwitch(branchId: string) {
        setSwitching(true);
        try {
            const response = await apiFetch('/api/v1/auth/switch-branch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ branch_id: branchId }),
            });

            if (response.ok) {
                if (activeCompany?.nit) {
                    localStorage.setItem(`${LAST_BRANCH_KEY}:${activeCompany.nit}`, branchId);
                }
                // #119: el backend devuelve `default_route` según el rol
                // del usuario (courier-only entra a /my-deliveries).
                let target = 'dashboard';
                try {
                    const data = (await response.clone().json()) as { default_route?: string };
                    if (typeof data?.default_route === 'string' && data.default_route !== '') {
                        target = data.default_route;
                    }
                } catch {
                    // si no hay JSON o falla, fallback a dashboard.
                }
                // El switch reemplaza la cookie JWT con el nuevo
                // `active_branch_id`. Toda la caché de React Query quedó bajo la
                // sede anterior (las queries con scope de sede NO incluyen
                // branch_id en su key porque la sede vive server-side en la
                // cookie), así que la limpiamos entera para no servir datos de la
                // sede previa dentro de la ventana de `staleTime`. Luego
                // refrescamos el contexto compartido (empresa, sede, permisos) y
                // esperamos antes de navegar para que la ruta destino monte con
                // la sede recién elegida.
                queryClient.clear();
                await reloadContext();
                navigate(route(target));
                return;
            }

            // 422 con code de bloqueo de caja → mostrar dialog accionable.
            if (response.status === 422) {
                try {
                    const body = await response.clone().json();
                    if (body?.code === 'BRANCH_SWITCH_BLOCKED_CASH_OPEN') {
                        setBlocker({
                            kind: 'cash_open',
                            openSessionId: body?.open_session_id ?? null,
                            branchId,
                        });
                        return;
                    }
                } catch {
                    // respuesta no-JSON, dejar caer.
                }
            }
        } finally {
            setSwitching(false);
        }
    }

    async function handleSelectBranch(branchId: string) {
        if (branchId === activeBranch?.id || switching) {
            return;
        }

        if (anyDirty) {
            setBlocker({ kind: 'dirty_state', branchId });
            return;
        }

        await performSwitch(branchId);
    }

    function handleConfirmBlocker() {
        if (blocker === null) return;

        if (blocker.kind === 'cash_open') {
            setBlocker(null);
            navigate('/orders/cashier');
            return;
        }

        if (blocker.kind === 'dirty_state') {
            const branchId = blocker.branchId;
            setBlocker(null);
            void performSwitch(branchId);
        }
    }

    function handleCancelBlocker() {
        if (switching) return;
        setBlocker(null);
    }

    // En colapsado los aux (label/Star/ChevronDown) se ocultan con `hidden`
    // en vez de solo `opacity-0`. Si solo se les baja la opacidad, siguen
    // ocupando layout dentro del cuadro size-8 y el `justify-center`
    // desplaza el MapPin a la izquierda. Con `hidden` el MapPin queda
    // perfectamente centrado en el ícono colapsado.
    const fade = 'transition-[opacity,transform] duration-200 ease-linear group-data-[collapsible=icon]:hidden';

    const trigger = (
        <div className="flex min-w-0 items-center gap-2 px-2 py-1.5 text-xs transition-[padding,gap,width] duration-200 ease-linear group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:gap-0 group-data-[collapsible=icon]:p-2">
            {switching ? (
                <LoaderCircle className="text-muted-foreground size-4 shrink-0 animate-spin" />
            ) : (
                <MapPin className="text-muted-foreground size-4 shrink-0" />
            )}
            <span className={cn('flex-1 truncate font-medium', fade)}>{activeBranch.name}</span>
            {activeBranch.is_default && <Star className={cn('size-3 shrink-0 fill-amber-500 text-amber-500', fade)} />}
            {hasMultiple && <ChevronDown className={cn('text-muted-foreground size-3 shrink-0', fade)} />}
        </div>
    );

    const dialogProps = (() => {
        if (blocker === null) {
            return null;
        }
        if (blocker.kind === 'cash_open') {
            return {
                title: 'Tienes una caja abierta',
                message:
                    'No puedes cambiar de sede mientras esta sede tenga una sesión de caja abierta. Ciérrala primero para evitar dejar el cuadre a medias.',
                confirmLabel: 'Ir a cierre de caja',
                cancelLabel: 'Quedarme aquí',
            };
        }
        return {
            title: 'Tienes cambios sin guardar',
            message: 'Si cambias de sede ahora, vas a perder los cambios que no hayas guardado en esta página. ¿Continuar de todas formas?',
            confirmLabel: 'Cambiar de sede',
            cancelLabel: 'Seguir editando',
        };
    })();

    return (
        <SidebarMenu className="group-data-[collapsible=icon]:items-center">
            <SidebarMenuItem>
                {hasMultiple || canManage ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                className="hover:bg-sidebar-accent focus-visible:ring-ring w-full rounded-md text-left transition-[width,colors] duration-200 ease-linear group-data-[collapsible=icon]:w-8 focus:outline-none focus-visible:ring-1"
                                disabled={switching}
                            >
                                {trigger}
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent side="right" align="start" className="min-w-[220px]">
                            {branches.map((branch) => (
                                <DropdownMenuItem
                                    key={branch.id}
                                    onSelect={() => handleSelectBranch(branch.id)}
                                    disabled={branch.id === activeBranch.id}
                                    className="gap-2"
                                >
                                    <MapPin className="text-muted-foreground size-4 shrink-0" />
                                    <span className="flex-1 truncate">{branch.name}</span>
                                    {branch.is_default && <Star className="size-3 shrink-0 fill-amber-500 text-amber-500" />}
                                    {branch.id === activeBranch.id && <span className="text-muted-foreground text-xs">activa</span>}
                                </DropdownMenuItem>
                            ))}
                            {canManage && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onSelect={() => navigate(route('company.branches'))} className="gap-2">
                                        <Plus className="text-muted-foreground size-4 shrink-0" />
                                        <span>Gestionar sedes</span>
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : (
                    <div>{trigger}</div>
                )}
            </SidebarMenuItem>
            {dialogProps && (
                <ConfirmDialog
                    open={blocker !== null}
                    title={dialogProps.title}
                    message={dialogProps.message}
                    confirmLabel={dialogProps.confirmLabel}
                    cancelLabel={dialogProps.cancelLabel}
                    loading={switching}
                    onConfirm={handleConfirmBlocker}
                    onCancel={handleCancelBlocker}
                />
            )}
        </SidebarMenu>
    );
}
