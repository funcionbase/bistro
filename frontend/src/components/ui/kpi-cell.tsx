import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface KpiCellProps {
    label: string;
    value: ReactNode;
    className?: string;
}

/**
 * Tarjeta compacta de KPI para grids 2-4 columnas (ver FRONTEND_UI_GUIDELINES §11).
 *
 * Background sutil `bg-muted/40`, label en uppercase tracking-wide y valor con
 * tipografia tabular para alinear digitos.
 */
export function KpiCell({ label, value, className }: KpiCellProps) {
    return (
        <div className={cn('bg-muted/40 rounded-lg p-3', className)}>
            <div className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">{label}</div>
            <div className="font-brand mt-1 text-base font-semibold tabular-nums md:text-lg">{value}</div>
        </div>
    );
}
