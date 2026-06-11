import { cn } from '@/lib/utils';

interface TipPickerProps {
    /** Monto base sobre el que se calcula la propina (total de la orden). */
    baseAmount: number;
    /** Valor actual de la propina (como string del input). */
    value: string;
    /** Llamada con el nuevo valor (string vacio = sin propina). */
    onChange: (value: string) => void;
    /** Porcentajes sugeridos. Default [10, 15, 20]. */
    percentages?: number[];
    /** Formateador de moneda inyectado por el padre. */
    formatCurrency: (value: number) => string;
    disabled?: boolean;
    className?: string;
}

/**
 * Chips de sugerencias de propina sobre un monto base. Cada chip aplica un
 * porcentaje redondeado al peso, mas un chip "Sin propina" para limpiar.
 *
 * El chip activo se resalta cuando el `value` actual matchea exactamente la
 * sugerencia (en string), lo que da feedback visual al cajero de que la
 * propina fue tomada de una sugerencia y no escrita a mano.
 *
 * La propina en Colombia es voluntaria (10% sugerida). El componente NO
 * persiste ni envia — solo facilita la entrada al `tipAmount` controlado
 * por el padre.
 */
export function TipPicker({ baseAmount, value, onChange, percentages = [10, 15, 20], formatCurrency, disabled, className }: TipPickerProps) {
    const isEmpty = value.trim() === '';

    return (
        <div className={cn('flex flex-wrap gap-1', className)}>
            {percentages.map((pct) => {
                const suggested = Math.round((baseAmount * pct) / 100);
                const active = !isEmpty && value === String(suggested);
                return (
                    <button
                        key={pct}
                        type="button"
                        onClick={() => onChange(String(suggested))}
                        disabled={disabled}
                        className={cn(
                            'rounded-full border px-2.5 py-0.5 text-xs transition',
                            'focus:ring-ring focus:ring-2 focus:outline-none',
                            'disabled:cursor-not-allowed disabled:opacity-50',
                            active ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted',
                        )}
                    >
                        {pct}% · {formatCurrency(suggested)}
                    </button>
                );
            })}
            <button
                type="button"
                onClick={() => onChange('')}
                disabled={disabled}
                className={cn(
                    'rounded-full border px-2.5 py-0.5 text-xs transition',
                    'focus:ring-ring focus:ring-2 focus:outline-none',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    isEmpty ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted',
                )}
            >
                Sin propina
            </button>
        </div>
    );
}
