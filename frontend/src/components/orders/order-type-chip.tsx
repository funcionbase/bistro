import { formatOrderTypeLabel, getOrderTypeMeta, type OrderType } from '@/lib/order-type';
import { cn } from '@/lib/utils';

/**
 * Paleta del chip por tipo de orden. Tokens semanticos del design system v3.1
 * (no tailwind plano), para que el dark mode funcione consistente.
 *
 *  - table   -> warning soft (mesa abierta requiere atencion del mesero)
 *  - delivery -> primary soft (movimiento / accion)
 *  - pickup  -> secondary (espera en mostrador)
 */
const TYPE_TONE: Record<OrderType, string> = {
    table: 'bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)]',
    delivery: 'bg-primary/10 text-primary',
    pickup: 'bg-secondary text-secondary-foreground',
};

interface OrderTypeChipProps {
    type: string | null | undefined;
    /** Si se provee y type es 'table', el label sera "Mesa N" en vez de "En sitio". */
    tableNumber?: string | number | null;
    className?: string;
}

/**
 * Chip pill con icono + label segun el tipo de orden. Usado en card del board
 * y en listings de orden/delivery donde se quiere mostrar el tipo de forma
 * compacta.
 *
 * Para detalles con icon + label en linea (no pill), preferir consumir
 * `getOrderTypeMeta` directamente desde `lib/order-type.ts`.
 */
export function OrderTypeChip({ type, tableNumber, className }: OrderTypeChipProps) {
    const meta = getOrderTypeMeta(type);
    if (!meta) return null;
    const Icon = meta.icon;
    const tone = TYPE_TONE[type as OrderType] ?? '';
    const label = formatOrderTypeLabel(type, tableNumber);

    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', tone, className)}>
            <Icon className="h-3 w-3" aria-hidden="true" />
            {label}
        </span>
    );
}
