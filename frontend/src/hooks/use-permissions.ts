import { useSharedData } from '@/lib/shared-data';
import { useMemo } from 'react';

export interface PermissionFlags {
    /** Lista cruda de slugs (e.g. `menu.read`, `orders.update`). */
    permissions: string[];
    /** Roles del sistema (owner/admin) bypasean toda verificación granular. */
    isSystem: boolean;
    /** Devuelve true si el usuario tiene el slug, o pertenece a un rol del sistema. */
    has: (slug: string) => boolean;
}

/**
 * Lee los permisos del usuario activo desde el SharedData de Inertia. Los roles
 * del sistema (`is_system: true`) tienen acceso total, en línea con la lógica
 * de `FeaturePermissionService::hasPermission` en backend.
 */
export function usePermissions(): PermissionFlags {
    const props = useSharedData();

    return useMemo(() => {
        const permissions = props.permissions ?? [];
        const isSystem = props.role?.is_system ?? false;
        return {
            permissions,
            isSystem,
            has: (slug: string) => isSystem || permissions.includes(slug),
        };
    }, [props.permissions, props.role?.is_system]);
}
