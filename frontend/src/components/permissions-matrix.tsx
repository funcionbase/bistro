import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useSharedData } from '@/lib/shared-data';
import { TOOLTIP_DELAY_MS } from '@/lib/shortcuts';
import { cn } from '@/lib/utils';
import type { CompanyRolePermission, Feature, RbacActionDescriptor } from '@/types';
import { Fragment, useEffect, useRef } from 'react';

interface PermissionsMatrixProps {
    features: Feature[];
    permissions: CompanyRolePermission[];
    readonly?: boolean;
    onChange?: (featureId: string, action: string, value: boolean) => void;
    onChangePerm?: (featureId: string, action: string, value: boolean) => void;
    disabledCheck?: (featureId: string, action: string) => boolean;
    onBulkToggleColumn?: (action: string, value: boolean) => void;
}

/**
 * Fallback embebido si los shared props de Inertia no llegan a tiempo
 * (e.g., render inicial sin JWT). Debe coincidir con `config/rbac.php`.
 */
const ACTIONS_FALLBACK: RbacActionDescriptor[] = [
    { key: 'can_create', label: 'Crear' },
    { key: 'can_read', label: 'Leer' },
    { key: 'can_update', label: 'Actualizar' },
    { key: 'can_delete', label: 'Eliminar' },
];

type ColumnState = 'none' | 'some' | 'all';

function HeaderToggle({ state, label, onToggle }: { state: ColumnState; label: string; onToggle: (next: boolean) => void }) {
    const ref = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (ref.current) {
            ref.current.indeterminate = state === 'some';
        }
    }, [state]);

    return (
        <label className="text-muted-foreground inline-flex cursor-pointer items-center gap-1 text-[10px] font-normal">
            <input
                ref={ref}
                type="checkbox"
                className="accent-primary h-3.5 w-3.5 cursor-pointer"
                checked={state === 'all'}
                onChange={(e) => onToggle(e.target.checked)}
                title={`Activar/desactivar todos los ${label}`}
            />
            <span>todos</span>
        </label>
    );
}

export default function PermissionsMatrix({
    features = [],
    permissions = [],
    readonly = false,
    onChange,
    onChangePerm,
    disabledCheck,
    onBulkToggleColumn,
}: PermissionsMatrixProps) {
    const handleChange = onChangePerm || onChange;
    const sharedActions = useSharedData().rbacActions;
    const ACTIONS: ReadonlyArray<RbacActionDescriptor> = sharedActions ?? ACTIONS_FALLBACK;
    const grouped = features.reduce<Record<string, Feature[]>>((acc, f) => {
        const group = f.group ?? 'General';
        if (!acc[group]) acc[group] = [];
        acc[group].push(f);
        return acc;
    }, {});

    const columnState = (actionKey: string): ColumnState => {
        if (features.length === 0) return 'none';
        let active = 0;
        for (const f of features) {
            const perm = permissions.find((p) => p.feature_id === f.id);
            if (perm && (perm[actionKey as keyof typeof perm] as unknown as boolean)) {
                active++;
            }
        }
        if (active === 0) return 'none';
        if (active === features.length) return 'all';
        return 'some';
    };

    if (features.length === 0) {
        return (
            <div className="overflow-x-auto rounded border">
                <table className="min-w-full">
                    <thead className="bg-muted/40">
                        <tr>
                            <th className="px-2 py-2 text-left text-xs font-semibold sm:px-4">Funcionalidad</th>
                            {ACTIONS.map((a) => (
                                <th key={a.key} className="px-2 py-2 text-center text-xs font-semibold sm:px-4">
                                    {a.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {[1, 2, 3].map((i) => (
                            <tr key={i}>
                                <td className="px-2 py-2 sm:px-4">
                                    <Skeleton className="h-4 w-32" />
                                </td>
                                {ACTIONS.map((a) => (
                                    <td key={a.key} className="px-2 py-2 text-center sm:px-4">
                                        <Skeleton className="mx-auto h-4 w-4" />
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    }

    const showBulkToggle = !readonly && !!onBulkToggleColumn;

    return (
        <div className="overflow-x-auto rounded border">
            <TooltipProvider delayDuration={TOOLTIP_DELAY_MS}>
                <table className="min-w-full">
                    <thead className="bg-muted/40">
                        <tr>
                            <th className="text-muted-foreground px-2 py-2 text-left text-xs font-semibold sm:px-4">Funcionalidad</th>
                            {ACTIONS.map((a) => (
                                <th key={a.key} className="text-muted-foreground px-2 py-2 text-center text-xs font-semibold sm:px-4">
                                    <div className="flex flex-col items-center gap-0.5">
                                        <span>{a.label}</span>
                                        {showBulkToggle && (
                                            <HeaderToggle
                                                state={columnState(a.key)}
                                                label={a.label.toLowerCase()}
                                                onToggle={(next) => onBulkToggleColumn?.(a.key, next)}
                                            />
                                        )}
                                    </div>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-border divide-y">
                        {Object.entries(grouped).map(([group, groupFeatures]) => (
                            <Fragment key={`group-${group}`}>
                                <tr className="bg-muted/20">
                                    <td colSpan={ACTIONS.length + 1} className="text-muted-foreground px-2 py-1 text-xs font-semibold tracking-wide uppercase sm:px-4">
                                        {group}
                                    </td>
                                </tr>
                                {groupFeatures.map((feature) => {
                                    const perm = permissions.find((p) => p.feature_id === feature.id);
                                    // Features owner-only no son asignables a roles no-sistema:
                                    // se muestran deshabilitadas en cualquier editor de roles/usuarios.
                                    const ownerOnly = !!feature.is_owner_only;
                                    return (
                                        <tr key={feature.id} className="hover:bg-muted/10">
                                            <td className="px-2 py-2 text-sm font-medium sm:px-4">
                                                {ownerOnly && (
                                                    <span className="text-muted-foreground mr-1.5 align-middle text-[10px] font-normal tracking-wide uppercase">
                                                        Solo dueño
                                                    </span>
                                                )}
                                                {feature.description ? (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span className="border-muted-foreground/40 cursor-help border-b border-dotted">
                                                                {feature.name}
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent className="max-w-xs">{feature.description}</TooltipContent>
                                                    </Tooltip>
                                                ) : (
                                                    <span>{feature.name}</span>
                                                )}
                                            </td>
                                            {ACTIONS.map((action) => {
                                                const isDisabled = readonly || ownerOnly || (disabledCheck && disabledCheck(feature.id, action.key));
                                                const isChecked = perm ? !!perm[action.key as keyof typeof perm] : false;
                                                return (
                                                    <td
                                                        key={action.key}
                                                        className={cn(
                                                            'px-2 py-2 text-center sm:px-4',
                                                            !isChecked && !isDisabled && 'bg-muted/5',
                                                            isDisabled && 'opacity-60',
                                                        )}
                                                    >
                                                        {isDisabled ? (
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <span className="inline-flex">
                                                                        <Checkbox checked={isChecked} disabled />
                                                                    </span>
                                                                </TooltipTrigger>
                                                                <TooltipContent>
                                                                    {ownerOnly
                                                                        ? 'Exclusivo del dueño — no asignable a otros roles'
                                                                        : disabledCheck && disabledCheck(feature.id, action.key)
                                                                          ? 'No tienes este permiso'
                                                                          : 'Sin permiso para modificar'}
                                                                </TooltipContent>
                                                            </Tooltip>
                                                        ) : (
                                                            <Checkbox
                                                                checked={isChecked}
                                                                onCheckedChange={(val) => handleChange?.(feature.id, action.key, !!val)}
                                                            />
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    );
                                })}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </TooltipProvider>
        </div>
    );
}
