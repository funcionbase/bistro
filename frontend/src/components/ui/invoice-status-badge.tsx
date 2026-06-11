import { cn } from '@/lib/utils';

export type InvoiceStatus = 'pending' | 'paid' | 'overdue' | 'voided';

interface InvoiceStatusBadgeProps {
    status: InvoiceStatus;
    /** Si true usa fuente y padding compactos (`text-[10px]`) para card-stack mobile. */
    compact?: boolean;
    className?: string;
}

const STATUS_LABELS: Record<InvoiceStatus, string> = {
    pending: 'Pendiente',
    paid: 'Pagada',
    overdue: 'Vencida',
    voided: 'Anulada',
};

const STATUS_TOKEN_CLASSES: Record<InvoiceStatus, string> = {
    pending: 'bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]',
    paid: 'bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe)]',
    overdue: 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]',
    voided: 'bg-muted text-muted-foreground',
};

/**
 * Pildora de estado de una factura del producto flexyflow.
 *
 * Tokens DS (`--color-status-*`) en lugar de las paletas Tailwind crudas
 * (`bg-yellow-100`, `bg-green-100`, `bg-red-100`, `bg-gray-100`) que
 * vivían duplicadas en `billing/index.tsx` y romperían dark mode.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo) y §13 (status colors).
 */
export function InvoiceStatusBadge({ status, compact = false, className }: InvoiceStatusBadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full font-semibold',
                compact ? 'px-2 py-0.5 text-[10px]' : 'px-2 py-0.5 text-xs',
                STATUS_TOKEN_CLASSES[status],
                className,
            )}
        >
            {STATUS_LABELS[status]}
        </span>
    );
}
