import type { InvoiceStatus } from '@/types';

interface Props {
    status: InvoiceStatus;
}

const CONFIG: Record<InvoiceStatus, { label: string; className: string }> = {
    pending: { label: 'Pendiente', className: 'bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]' },
    paid: { label: 'Pagada', className: 'bg-[color:var(--color-status-success)]/15 text-[color:var(--color-status-success)]' },
    overdue: { label: 'Vencida', className: 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]' },
    voided: { label: 'Anulada', className: 'bg-muted text-muted-foreground' },
};

export default function InvoiceStatusBadge({ status }: Props) {
    const { label, className } = CONFIG[status] ?? CONFIG.pending;
    return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${className}`}>{label}</span>;
}
