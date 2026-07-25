import { Search } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

interface FilterBarProps {
    searchValue: string;
    onSearchChange: (value: string) => void;
    searchPlaceholder?: string;
    searchClassName?: string;
    variant?: 'plain' | 'card';
    children?: ReactNode;
    className?: string;
}

/**
 * Barra de busqueda + filtros para listings (ver FRONTEND_UI_GUIDELINES §9, §10).
 *
 * - Search input con icono lupa siempre presente, full-width en mobile y flex-1 en sm+.
 * - Slot `children` para Selects, Checkboxes y otros filtros adicionales.
 *   En mobile (`<sm`) cada hijo directo se estira al ancho de la barra; en `sm+`
 *   recupera su ancho intrínseco. Para mantener este contrato, los Selects deben
 *   declarar `<SelectTrigger className="w-full sm:w-auto sm:min-w-[160px]">`.
 * - `variant="card"` envuelve la barra en un contenedor con borde y fondo card.
 */
export function FilterBar({
    searchValue,
    onSearchChange,
    searchPlaceholder = 'Buscar…',
    searchClassName,
    variant = 'plain',
    children,
    className,
}: FilterBarProps) {
    // Debounce interno (~300ms): el input responde tecla a tecla pero
    // onSearchChange (que suele disparar fetch/filtrado) se emite una vez que
    // el usuario deja de tipear. Ningún caller cambia.
    const [localValue, setLocalValue] = useState(searchValue);

    // Sincroniza cambios externos (reset de filtros desde el padre).
    useEffect(() => {
        setLocalValue(searchValue);
    }, [searchValue]);

    useEffect(() => {
        if (localValue === searchValue) {
            return;
        }
        const t = setTimeout(() => onSearchChange(localValue), 300);
        return () => clearTimeout(t);
    }, [localValue, searchValue, onSearchChange]);

    return (
        <div
            className={cn(
                'flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3',
                variant === 'card' && 'bg-card rounded-lg border p-3 sm:items-end',
                '[&>*]:w-full sm:[&>*]:w-auto',
                className,
            )}
        >
            <div className={cn('relative w-full sm:flex-1 sm:min-w-[220px]', searchClassName)}>
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                <Input
                    type="text"
                    value={localValue}
                    onChange={(e) => setLocalValue(e.target.value)}
                    placeholder={searchPlaceholder}
                    className="pl-9"
                />
            </div>
            {children}
        </div>
    );
}
