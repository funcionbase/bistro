interface DeliveryStatusBadgeProps {
    activeDeliveries: number;
    maxAllowed: number;
}

function getColorClass(ratio: number): string {
    if (ratio >= 1) return 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]';
    if (ratio >= 0.66) return 'bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]';
    return 'bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe)]';
}

function getStatusLabel(ratio: number): string {
    if (ratio >= 1) return 'FULL';
    if (ratio >= 0.66) return 'Media';
    return 'Disponible';
}

export function DeliveryStatusBadge({ activeDeliveries, maxAllowed }: DeliveryStatusBadgeProps) {
    const ratio = maxAllowed > 0 ? activeDeliveries / maxAllowed : 0;

    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${getColorClass(ratio)}`}>
            {activeDeliveries}/{maxAllowed} · {getStatusLabel(ratio)}
        </span>
    );
}
