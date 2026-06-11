import type { BadgeProps } from '@/components/ui/badge';
import { Badge } from '@/components/ui/badge';
import type { ClientSegment } from '@/hooks/use-clients';

interface SegmentMeta {
    label: string;
    variant: BadgeProps['variant'];
}

/**
 * Mapeo de segmentos CRM a variants semanticas del Badge.
 *
 *  - vip -> `accent` (lime, top de piramide — cumple §9 lime para loyalty)
 *  - recurrent -> `safe` (verde semaforo, cliente saludable)
 *  - new -> `default` (azul primary, recien llegado, accion-prompt)
 *  - inactive -> `secondary` (gris atenuado, dormido)
 *  - at_risk -> `critical` (rojo semaforo, peligro de churn)
 *  - regular -> `outline` (neutro, sin urgencia)
 */
const SEGMENT_META: Record<ClientSegment, SegmentMeta> = {
    vip: { label: 'VIP', variant: 'accent' },
    recurrent: { label: 'Recurrente', variant: 'safe' },
    new: { label: 'Nuevo', variant: 'default' },
    inactive: { label: 'Inactivo', variant: 'secondary' },
    at_risk: { label: 'En riesgo', variant: 'critical' },
    regular: { label: 'Regular', variant: 'outline' },
};

interface SegmentBadgeProps {
    segment: ClientSegment;
    className?: string;
}

export function SegmentBadge({ segment, className }: SegmentBadgeProps) {
    const meta = SEGMENT_META[segment] ?? SEGMENT_META.regular;
    return (
        <Badge variant={meta.variant} className={className}>
            {meta.label}
        </Badge>
    );
}

export function segmentLabel(segment: ClientSegment): string {
    return SEGMENT_META[segment]?.label ?? 'Regular';
}
