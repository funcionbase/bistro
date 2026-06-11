import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import type { RestaurantStatus } from '@/types/business-hours';

const REASON_LABELS: Record<string, string> = {
    within_hours: 'dentro del horario',
    out_of_hours: 'fuera del horario',
    closed_by_exception: 'cerrado por excepción',
    open_by_exception: 'abierto por excepción',
    not_in_service_window: 'fuera de ventana especial',
    no_schedule_defined: 'sin horario configurado',
};

const MENU_VISIBILITY_LABELS: Record<string, string> = {
    visible: 'Menú público visible',
    restaurant_closed: 'Menú oculto — empresa cerrada',
    exception_closed: 'Menú oculto — excepción de cierre',
    not_in_service_window: 'Menú oculto — fuera del horario especial',
};

interface OpenStatusBadgeProps {
    status: RestaurantStatus | null;
    loading: boolean;
}

export function OpenStatusBadge({ status, loading }: OpenStatusBadgeProps) {
    if (loading || !status) {
        return <Skeleton className="h-8 w-40" />;
    }

    const { is_open: isOpen, reason, exception_active, next_opening, menu_available, menu_visibility_reason } = status;

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
                <Badge variant={isOpen ? 'safe' : 'critical'} className="gap-1.5 px-3 py-1 text-sm">
                    <span
                        className={`h-2 w-2 rounded-full ${
                            isOpen ? 'bg-[color:var(--color-status-safe)]' : 'bg-[color:var(--color-status-critical)]'
                        }`}
                    />
                    {isOpen ? 'Abierto ahora' : 'Cerrado ahora'}
                </Badge>

                <span className="text-muted-foreground text-xs">
                    {REASON_LABELS[reason] ?? reason}
                    {exception_active && (
                        <Badge variant="warning" className="ml-2 px-1.5 py-0.5 text-[11px]">
                            excepción activa
                        </Badge>
                    )}
                </span>

                {!isOpen && next_opening && (
                    <span className="text-muted-foreground text-xs">
                        Próxima apertura: <strong className="text-foreground">{next_opening.day}</strong> a las{' '}
                        <strong className="text-foreground">{next_opening.time}</strong>
                    </span>
                )}
            </div>

            <Badge variant={menu_available ? 'safe' : 'secondary'} className="w-fit gap-1 px-2 py-0.5 text-xs">
                <span className={`h-1.5 w-1.5 rounded-full ${menu_available ? 'bg-[color:var(--color-status-safe)]' : 'bg-muted-foreground'}`} />
                {MENU_VISIBILITY_LABELS[menu_visibility_reason] ?? menu_visibility_reason}
            </Badge>
        </div>
    );
}
