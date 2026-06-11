import { Badge } from '@/components/ui/badge';
import type { RestaurantMenu } from '@/types';

interface MenuStatusBadgeProps {
    menu: Pick<RestaurantMenu, 'status' | 'active_days'>;
    className?: string;
}

/**
 * Badge que indica el estado de publicacion de un menu, derivado de su
 * `status` y `active_days`.
 *
 *  - Borrador (`status === 'draft'`): tono neutro (`secondary`). El menu
 *    aun no se sirve en ninguna sede.
 *  - Programado (`status !== 'draft'` + `active_days` configurados): tono
 *    primario (`default`/azul). Indica que el menu rota por dias.
 *  - Activo (`status !== 'draft'` y sin `active_days`): lime (`accent`),
 *    siguiendo §3 de la guia — menu permanente en produccion es un
 *    estado de "logro".
 *
 * NOTA: un menu publicado puede tener `status === 'active'` hoy si el
 * scheduler lo activo por coincidencia de dias. Visualmente preferimos
 * mostrar "Programado" cuando hay `active_days` configurados, para que
 * solo el menu permanente aparezca como "Activo" — evita la confusion de
 * "dos menus activos" en la misma sede.
 */
export function MenuStatusBadge({ menu, className }: MenuStatusBadgeProps) {
    const isScheduled = Array.isArray(menu.active_days) && menu.active_days.length > 0;
    const isDraft = menu.status === 'draft';

    if (isDraft) {
        return (
            <Badge variant="secondary" className={className}>
                Borrador
            </Badge>
        );
    }

    if (isScheduled) {
        return (
            <Badge variant="default" className={className}>
                Programado
            </Badge>
        );
    }

    return (
        <Badge variant="accent" className={className}>
            Activo
        </Badge>
    );
}
