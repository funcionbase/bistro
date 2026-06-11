import { formatCurrency } from '@/lib/coupon-helpers';
import type { CouponType } from '@/types/coupon';
import { Tag, X } from 'lucide-react';

interface CouponAppliedBadgeProps {
    code: string;
    discountType: CouponType;
    discountValue: number;
    discountAmount: number;
    onRemove?: () => void;
}

/**
 * Indicador de cupon aplicado al carrito. Tono `status-safe` (verde semaforo)
 * porque el cliente acaba de ahorrar — es feedback positivo de la accion.
 * Mantiene layout custom (boton X integrado) en lugar de usar `Alert`
 * porque el descuento se muestra inline con el codigo.
 */
export function CouponAppliedBadge({ code, discountType, discountValue, discountAmount, onRemove }: CouponAppliedBadgeProps) {
    const label = discountType === 'percentage' ? `${discountValue}% desc.` : `${formatCurrency(discountValue)} desc.`;

    return (
        <div className="flex items-center justify-between rounded-lg border border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 px-4 py-2.5">
            <div className="flex items-center gap-2">
                <Tag className="h-4 w-4 text-[color:var(--color-status-safe)]" />
                <span className="font-mono text-sm font-bold text-[color:var(--color-status-safe)]">{code}</span>
                <span className="rounded-full bg-[color:var(--color-status-safe)]/15 px-2 py-0.5 text-xs text-[color:var(--color-status-safe)]">
                    {label}
                </span>
                <span className="text-sm font-semibold text-[color:var(--color-status-safe)] tabular-nums">−{formatCurrency(discountAmount)}</span>
            </div>
            {onRemove && (
                <button
                    onClick={onRemove}
                    className="rounded p-0.5 text-[color:var(--color-status-safe)] transition-colors hover:bg-[color:var(--color-status-safe)]/15"
                    title="Remover cupón"
                >
                    <X className="h-4 w-4" />
                </button>
            )}
        </div>
    );
}
