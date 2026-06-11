import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { cn } from '@/lib/utils';
import { useEffect, useMemo, useState } from 'react';

interface NotesEditorProps {
    /** Valor inicial controlado (uncontrolled si se omite). */
    value?: string;
    /** Callback cuando cambia el valor (debounced no — el caller maneja persistencia). */
    onChange: (next: string) => void;
    /** Etiqueta visible encima del textarea. Default: "Notas". */
    label?: string;
    /** Placeholder del textarea. */
    placeholder?: string;
    /** Si se pasa, el textarea queda deshabilitado. */
    disabled?: boolean;
    /** Máximo de caracteres (default 500 — alineado con order_items.notes / order_notes.body). */
    maxLength?: number;
    /**
     * Botones rápidos opcionales que insertan/agregan texto al notes. Útil para
     * notas comunes ("sin cebolla", "sin sal"). El click ANEXA si ya hay texto.
     */
    quickActions?: ReadonlyArray<string>;
    className?: string;
    /** id HTML del textarea para el label. */
    id?: string;
}

/**
 * Editor reutilizable de notas con contador y botones rápidos.
 *
 * Pensado para el flujo de mesa con QR (#191): notas individuales por item
 * en el carrito del comensal, notas grupales/cocina en el detalle, y el
 * mesero editando notas en la pantalla de aprobación. La capa de DB siempre
 * recorta a 500 caracteres; este componente refleja el límite en UI.
 */
export function NotesEditor({
    value,
    onChange,
    label = 'Notas',
    placeholder = 'Cualquier ajuste especial: sin cebolla, término medio, etc.',
    disabled = false,
    maxLength = 500,
    quickActions,
    className,
    id,
}: NotesEditorProps) {
    const [internal, setInternal] = useState(value ?? '');
    useEffect(() => {
        if (value !== undefined) setInternal(value);
    }, [value]);

    const length = useMemo(() => internal.length, [internal]);
    const isNearMax = length >= maxLength * 0.85;

    const emit = (next: string) => {
        const sanitized = sanitizePlainText(next, maxLength, true, false);
        setInternal(sanitized);
        onChange(sanitized);
    };

    const appendQuick = (chip: string) => {
        if (disabled) return;
        const sep = internal.trim() === '' ? '' : ', ';
        emit(`${internal}${sep}${chip}`);
    };

    return (
        <div className={cn('space-y-1.5', className)}>
            {label && (
                <div className="flex items-center justify-between">
                    <Label htmlFor={id} className="text-xs">
                        {label}
                    </Label>
                    <span className={cn('text-xs tabular-nums', isNearMax ? 'text-[color:var(--color-status-warning)]' : 'text-muted-foreground')}>
                        {length}/{maxLength}
                    </span>
                </div>
            )}
            <textarea
                id={id}
                className={cn(
                    'border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[72px] w-full resize-y rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50',
                )}
                value={internal}
                onChange={(e) => emit(e.target.value)}
                placeholder={placeholder}
                maxLength={maxLength}
                disabled={disabled}
            />
            {quickActions && quickActions.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {quickActions.map((chip) => (
                        <Button
                            key={chip}
                            type="button"
                            size="sm"
                            variant="secondary"
                            disabled={disabled}
                            onClick={() => appendQuick(chip)}
                            className="h-7 px-2 text-xs"
                        >
                            + {chip}
                        </Button>
                    ))}
                </div>
            )}
        </div>
    );
}
