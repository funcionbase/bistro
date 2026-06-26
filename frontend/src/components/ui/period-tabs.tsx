import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Segmented control para seleccionar un período (Hoy / Semana / Mes / Custom)
 * con soporte opcional de rango de fechas cuando el valor activo es 'custom'.
 *
 * Comparte el patrón visual con `BranchFilterTabs` (botones pill con border en
 * estado inactivo, primario sólido en estado activo). Cuando ambos coinciden en
 * la misma vista, quedan alineados con `gap-2`.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §3 (tokens) y §7 (componentes reutilizables).
 */
interface PeriodOption<T extends string = string> {
    value: T;
    label: string;
}

interface PeriodTabsProps<T extends string = string> {
    options: ReadonlyArray<PeriodOption<T>>;
    value: T;
    onChange: (value: T) => void;
    /** Mostrar inputs de fechas y botón Aplicar cuando value === 'custom'. */
    customValue?: T;
    dateFrom?: string;
    dateTo?: string;
    onDateFromChange?: (value: string) => void;
    onDateToChange?: (value: string) => void;
    onApplyCustom?: () => void;
    applyDisabled?: boolean;
    /** Mostrar un único input de fecha cuando value === specificDayValue. */
    specificDayValue?: T;
    specificDay?: string;
    onSpecificDayChange?: (value: string) => void;
    className?: string;
}

const baseTab = 'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors';
const activeTab = 'bg-primary text-primary-foreground';
const inactiveTab = 'border-border text-foreground hover:bg-muted border';

export function PeriodTabs<T extends string = string>({
    options,
    value,
    onChange,
    customValue,
    dateFrom = '',
    dateTo = '',
    onDateFromChange,
    onDateToChange,
    onApplyCustom,
    applyDisabled,
    specificDayValue,
    specificDay = '',
    onSpecificDayChange,
    className,
}: PeriodTabsProps<T>) {
    const showCustomInputs = customValue !== undefined && value === customValue;
    const showSpecificDayInput = specificDayValue !== undefined && value === specificDayValue;

    return (
        <div className={cn('flex flex-wrap items-center gap-2', className)}>
            {options.map(({ value: optionValue, label }) => (
                <button
                    key={optionValue}
                    type="button"
                    onClick={() => onChange(optionValue)}
                    className={cn(baseTab, value === optionValue ? activeTab : inactiveTab)}
                >
                    {label}
                </button>
            ))}

            {showSpecificDayInput && (
                <Input
                    type="date"
                    value={specificDay}
                    onChange={(e) => onSpecificDayChange?.(e.target.value)}
                    className="border-border w-full max-w-[10rem] sm:w-36"
                />
            )}

            {showCustomInputs && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        type="date"
                        value={dateFrom}
                        onChange={(e) => onDateFromChange?.(e.target.value)}
                        className="border-border w-full max-w-[10rem] sm:w-36"
                    />
                    <span className="text-muted-foreground text-sm">–</span>
                    <Input
                        type="date"
                        value={dateTo}
                        min={dateFrom || undefined}
                        onChange={(e) => onDateToChange?.(e.target.value)}
                        className="border-border w-full max-w-[10rem] sm:w-36"
                    />
                    <Button size="sm" disabled={applyDisabled} onClick={onApplyCustom}>
                        Aplicar
                    </Button>
                </div>
            )}
        </div>
    );
}
