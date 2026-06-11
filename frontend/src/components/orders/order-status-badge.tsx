import { Badge, type BadgeProps } from '@/components/ui/badge';
import { useOrderStatuses } from '@/hooks/use-order-statuses';
import { statusLabel } from '@/lib/order-status';
import { cn } from '@/lib/utils';

type StatusTone = { kind: 'variant'; variant: BadgeProps['variant'] } | { kind: 'class'; className: string };

/**
 * Mapeo de status -> tono semantico. Diferencia visualmente:
 *  - operativos no urgentes (pending) -> secondary neutro
 *  - operativos activos (in_kitchen) -> warning
 *  - operativos en accion (ready, in_transit) -> tonos de primary
 *  - terminales positivos (completed) -> safe
 *  - terminales negativos (failed, cancelled) -> critical
 *  - devoluciones (refunded) -> warning (no es error, requiere atencion)
 *  - abandonadas -> secondary (sin energia)
 *
 * Si el status no esta mapeado se usa outline como fallback neutro.
 */
const STATUS_TONE: Record<string, StatusTone> = {
    pending: { kind: 'variant', variant: 'secondary' },
    in_kitchen: { kind: 'variant', variant: 'warning' },
    ready: {
        kind: 'class',
        className: 'border-transparent bg-primary/15 text-primary',
    },
    in_transit: { kind: 'variant', variant: 'default' },
    completed: { kind: 'variant', variant: 'safe' },
    failed: { kind: 'variant', variant: 'critical' },
    cancelled: { kind: 'variant', variant: 'critical' },
    refunded: { kind: 'variant', variant: 'warning' },
    abandoned: { kind: 'variant', variant: 'secondary' },
};

interface OrderStatusBadgeProps {
    status: string;
    /** Override del label. Por default usa `statusLabel(orderStatuses, status)`. */
    label?: string;
    /** Tamaño del badge — 'sm' baja el padding para tablas densas. */
    size?: 'default' | 'sm';
    className?: string;
}

/**
 * Badge canonico para mostrar el estado de una orden. Reemplaza la dependencia
 * de `statusBadgeClass` (que vive en el config del backend con tailwind plano)
 * por variants/className con tokens semanticos del design system v3.1.
 *
 * Consumido por board.tsx (card del kanban), order-detail-modal y deliveries
 * listing. Para extender con nuevos estados, agregar entrada en STATUS_TONE.
 */
export function OrderStatusBadge({ status, label, size = 'default', className }: OrderStatusBadgeProps) {
    const orderStatuses = useOrderStatuses();
    const resolvedLabel = label ?? statusLabel(orderStatuses, status);
    const tone = STATUS_TONE[status] ?? { kind: 'variant', variant: 'outline' as const };

    const sizeClass = size === 'sm' ? 'text-[11px] px-2 py-0' : 'text-xs';

    if (tone.kind === 'variant') {
        return (
            <Badge variant={tone.variant} className={cn(sizeClass, className)}>
                {resolvedLabel}
            </Badge>
        );
    }

    return <Badge className={cn(sizeClass, tone.className, className)}>{resolvedLabel}</Badge>;
}
