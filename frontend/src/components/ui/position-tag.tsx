import { cn } from '@/lib/utils';

interface PositionTagProps {
    /**
     * Color HEX (e.g. `#C0FD79`) o `null` para usar tokens neutros del DS.
     * Cuando hay color custom (definido en `employee_positions.color`),
     * se aplica al borde, texto y dot — sin tocar el background.
     */
    color: string | null;
    label: string;
    className?: string;
}

/**
 * Píldora redonda con dot de color que identifica el cargo de un colaborador.
 *
 * Reutilizable para listings, drawers, perfil propio (/me) y vistas de detalle.
 * Si la position no tiene color asignado cae a tokens neutros (`border-border`,
 * `bg-muted-foreground/40`).
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo de componentes shared).
 */
export function PositionTag({ color, label, className }: PositionTagProps) {
    if (!color) {
        return (
            <span
                className={cn(
                    'border-border text-foreground inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs',
                    className,
                )}
            >
                <span className="bg-muted-foreground/40 h-2 w-2 rounded-full" />
                {label}
            </span>
        );
    }
    return (
        <span
            className={cn('inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs', className)}
            style={{ borderColor: color, color }}
        >
            <span className="h-2 w-2 rounded-full" style={{ background: color }} />
            {label}
        </span>
    );
}
