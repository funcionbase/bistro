interface Props {
    type: string;
}

export default function InvoiceTypeChip({ type }: Props) {
    if (type === 'proration') {
        return (
            <span className="inline-flex items-center rounded-full bg-[color:var(--color-category-violet)]/15 px-2.5 py-0.5 text-xs font-semibold text-[color:var(--color-category-violet)]">
                Prorrateo
            </span>
        );
    }
    return (
        <span className="inline-flex items-center rounded-full bg-[color:var(--color-status-info)]/15 px-2.5 py-0.5 text-xs font-semibold text-[color:var(--color-status-info)]">
            Mensual
        </span>
    );
}
