import { Badge } from '@/components/ui/badge';

interface AvailabilityBadgeProps {
    available: boolean;
    className?: string;
}

/**
 * Badge "Disponible / No disponible" para items de menu.
 *
 * Usa variants semanticas del Badge:
 *  - Disponible -> `safe` (verde semaforo, item activo en menu).
 *  - No disponible -> `secondary` (neutro, atenuado pero no critico).
 *
 * No uso `accent` (lime) aqui aunque el visual original lo usaba, porque
 * §3 reserva el lime para momentos de logro/CTA unico — un item simplemente
 * disponible no califica. `safe` comunica el estado operativo sin gastar
 * la atencion limitada del lime.
 */
export function AvailabilityBadge({ available, className }: AvailabilityBadgeProps) {
    return (
        <Badge variant={available ? 'safe' : 'secondary'} className={className}>
            {available ? 'Disponible' : 'No disponible'}
        </Badge>
    );
}
