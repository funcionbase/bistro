import { Badge } from '@/components/ui/badge';
import { formatCouponType } from '@/lib/coupon-helpers';
import type { CouponType } from '@/types/coupon';
import { DollarSign, Percent } from 'lucide-react';

interface CouponTypeBadgeProps {
    type: CouponType;
}

/**
 * Badge para el tipo de descuento del cupon. Tonos neutros (outline) para
 * no competir con el StatusBadge — el "tipo" es metadata estructural, no
 * estado operativo. §3 reserva el lime para logro/CTA, no para metadata.
 */
export function CouponTypeBadge({ type }: CouponTypeBadgeProps) {
    const isPercentage = type === 'percentage';

    return (
        <Badge variant="outline" className="gap-1">
            {isPercentage ? <Percent className="h-3 w-3" /> : <DollarSign className="h-3 w-3" />}
            {formatCouponType(type)}
        </Badge>
    );
}
