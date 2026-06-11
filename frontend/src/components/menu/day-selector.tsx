import { cn } from '@/lib/utils';

interface DaySelectorProps {
    selected: number[];
    onChange: (days: number[]) => void;
}

const DAYS = [
    { value: 0, label: 'Dom' },
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mié' },
    { value: 4, label: 'Jue' },
    { value: 5, label: 'Vie' },
    { value: 6, label: 'Sáb' },
];

export default function DaySelector({ selected, onChange }: DaySelectorProps) {
    function toggle(day: number) {
        if (selected.includes(day)) {
            onChange(selected.filter((d) => d !== day));
        } else {
            onChange([...selected, day].sort((a, b) => a - b));
        }
    }

    return (
        <div className="flex gap-2">
            {DAYS.map(({ value, label }) => {
                const isActive = selected.includes(value);
                return (
                    <button
                        key={value}
                        type="button"
                        onClick={() => toggle(value)}
                        aria-pressed={isActive}
                        className={cn(
                            'focus:ring-ring h-9 w-9 rounded-full text-xs font-medium transition-colors focus:ring-2 focus:outline-none',
                            isActive ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-secondary',
                        )}
                    >
                        {label}
                    </button>
                );
            })}
        </div>
    );
}
