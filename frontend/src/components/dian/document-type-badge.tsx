import { Badge } from '@/components/ui/badge';
import { type DianDocumentType, DIAN_DOC_TYPE_LABELS } from '@/types/dian';
import { cn } from '@/lib/utils';

interface Props {
    type: DianDocumentType;
    className?: string;
}

export function DocumentTypeBadge({ type, className }: Props) {
    const isPos = type === 'pos_equivalent' || type === 'pos_equivalent_credit_note';

    return (
        <Badge
            variant="outline"
            className={cn(
                isPos
                    ? 'bg-[color:var(--color-status-info)]/10 text-[color:var(--color-status-info)] border-[color:var(--color-status-info)]/30'
                    : 'bg-[color:var(--color-status-success)]/10 text-[color:var(--color-status-success)] border-[color:var(--color-status-success)]/30',
                'font-medium',
                className,
            )}
        >
            {DIAN_DOC_TYPE_LABELS[type]}
        </Badge>
    );
}
