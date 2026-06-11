import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface DetailRowProps {
    label: string;
    value: ReactNode;
    valueClassName?: string;
    uppercase?: boolean;
    className?: string;
}

/**
 * Par label / value para drawers y tarjetas de detalle (ver FRONTEND_UI_GUIDELINES §11).
 *
 * - Label en text-xs muted-foreground.
 * - Value debajo, formatable via `valueClassName` (tabular-nums, font-semibold, etc.).
 * - `uppercase` opcional para labels tipo "Etiqueta" (uppercase tracking-wide).
 */
export function DetailRow({ label, value, valueClassName, uppercase, className }: DetailRowProps) {
    return (
        <div className={className}>
            <div
                className={cn(
                    'text-muted-foreground text-xs',
                    uppercase && 'uppercase tracking-wide',
                )}
            >
                {label}
            </div>
            <div className={cn('mt-0.5', valueClassName)}>{value}</div>
        </div>
    );
}
