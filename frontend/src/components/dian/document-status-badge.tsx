import { Badge } from '@/components/ui/badge';
import { type DianDocumentStatus, DIAN_STATUS_LABELS } from '@/types/dian';
import { cn } from '@/lib/utils';

/**
 * Badge de status de un documento DIAN, con color semántico del DS
 * (var(--color-status-*)). Cero hex hardcoded.
 */
interface Props {
    status: DianDocumentStatus;
    className?: string;
}

const STATUS_CLASSES: Record<DianDocumentStatus, string> = {
    accepted: 'bg-[color:var(--color-status-success)]/10 text-[color:var(--color-status-success)] border-[color:var(--color-status-success)]/30',
    sent: 'bg-[color:var(--color-status-info)]/10 text-[color:var(--color-status-info)] border-[color:var(--color-status-info)]/30',
    queued: 'bg-[color:var(--color-status-info)]/10 text-[color:var(--color-status-info)] border-[color:var(--color-status-info)]/30',
    pending: 'bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)] border-[color:var(--color-status-warning)]/30',
    rejected: 'bg-[color:var(--color-status-critical)]/10 text-[color:var(--color-status-critical)] border-[color:var(--color-status-critical)]/30',
    error: 'bg-[color:var(--color-status-critical)]/10 text-[color:var(--color-status-critical)] border-[color:var(--color-status-critical)]/30',
    needs_recipient_data: 'bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)] border-[color:var(--color-status-warning)]/30',
};

export function DocumentStatusBadge({ status, className }: Props) {
    return (
        <Badge variant="outline" className={cn(STATUS_CLASSES[status], 'font-medium', className)}>
            {DIAN_STATUS_LABELS[status]}
        </Badge>
    );
}
