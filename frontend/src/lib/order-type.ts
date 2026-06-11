import { Bike, ShoppingBag, Utensils, type LucideIcon } from 'lucide-react';

export type OrderType = 'table' | 'delivery' | 'pickup';

export interface OrderTypeMeta {
    label: string;
    icon: LucideIcon;
}

/**
 * Metadatos canonicos por tipo de orden. Fuente unica de verdad para label + icon.
 * Consumido por board, detalle de orden, deliveries, caja.
 */
export const ORDER_TYPE_META: Record<OrderType, OrderTypeMeta> = {
    table: { label: 'En sitio', icon: Utensils },
    delivery: { label: 'Domicilio', icon: Bike },
    pickup: { label: 'Para llevar', icon: ShoppingBag },
};

/**
 * Devuelve los metadatos para un order_type arbitrario o null si no esta en el enum.
 */
export function getOrderTypeMeta(type: string | null | undefined): OrderTypeMeta | null {
    if (!type || !(type in ORDER_TYPE_META)) return null;
    return ORDER_TYPE_META[type as OrderType];
}

/**
 * Formato compacto del label cuando se quiere mostrar tipo + numero en un solo string.
 *
 *  - table con tableNumber → "Mesa 5"
 *  - table sin tableNumber → "En sitio"
 *  - otros types → label canonico ("Domicilio" / "Para llevar")
 *  - type desconocido → "" (string vacio)
 */
export function formatOrderTypeLabel(type: string | null | undefined, tableNumber?: string | number | null): string {
    const meta = getOrderTypeMeta(type);
    if (!meta) return '';
    if (type === 'table' && tableNumber !== null && tableNumber !== undefined && tableNumber !== '') {
        return `Mesa ${tableNumber}`;
    }
    return meta.label;
}
