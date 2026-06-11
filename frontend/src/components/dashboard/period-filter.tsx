import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useIsMobile } from '@/hooks/use-mobile';
import type { Period } from '@/types';

const PERIODS: { value: Period; label: string }[] = [
    { value: 'today', label: 'Hoy' },
    { value: 'week', label: 'Semana' },
    { value: 'month', label: 'Mes' },
];

interface PeriodFilterProps {
    value: Period;
    onChange: (period: Period) => void;
    disabled?: boolean;
}

export default function PeriodFilter({ value, onChange, disabled = false }: PeriodFilterProps) {
    const isMobile = useIsMobile();

    if (isMobile) {
        return (
            <Select value={value} onValueChange={(v) => onChange(v as Period)} disabled={disabled}>
                <SelectTrigger className="min-h-[44px] w-32">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {PERIODS.map((p) => (
                        <SelectItem key={p.value} value={p.value}>
                            {p.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    }

    return (
        <div className="flex items-center gap-1 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-body)] p-0.5">
            {PERIODS.map((p) => (
                <button
                    key={p.value}
                    onClick={() => onChange(p.value)}
                    disabled={disabled}
                    className={`rounded-md px-3 py-1 text-sm font-medium transition-colors ${
                        value === p.value
                            ? 'bg-[var(--color-primary)] text-white shadow-sm'
                            : 'text-[var(--color-text-default)] hover:bg-[var(--color-border-light)]'
                    } disabled:cursor-not-allowed disabled:opacity-60`}
                >
                    {p.label}
                </button>
            ))}
        </div>
    );
}
