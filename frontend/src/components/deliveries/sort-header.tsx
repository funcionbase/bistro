import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';

/** Columnas ordenables de la tabla de ventas del día. */
export type SortColumn = 'id' | 'datetime' | 'type' | 'status' | 'location' | 'courier' | 'total';
export type SortDirection = 'asc' | 'desc';

interface SortHeaderProps {
    label: string;
    column: SortColumn;
    activeColumn: SortColumn;
    direction: SortDirection;
    onClick: (col: SortColumn) => void;
    align?: 'left' | 'right';
}

/** Header de tabla clickable con flecha que indica el orden activo. */
export function SortHeader({ label, column, activeColumn, direction, onClick, align = 'left' }: SortHeaderProps) {
    const isActive = activeColumn === column;
    const Icon = isActive ? (direction === 'asc' ? ArrowUp : ArrowDown) : ChevronsUpDown;
    return (
        <th
            className={`px-3 py-2 ${align === 'right' ? 'text-right' : 'text-left'} hover:bg-muted cursor-pointer select-none`}
            onClick={() => onClick(column)}
        >
            <span className={`inline-flex items-center gap-1 ${align === 'right' ? 'flex-row-reverse' : ''}`}>
                {label}
                <Icon className={`h-3 w-3 ${isActive ? 'text-foreground' : 'text-muted-foreground/50'}`} />
            </span>
        </th>
    );
}
