import type { BadgeProps } from '@/components/ui/badge';
import { Badge } from '@/components/ui/badge';
import { getCouponStatus } from '@/lib/coupon-helpers';
import type { Coupon } from '@/types/coupon';
import { CheckCircle, CircleOff, Clock, Flame, type LucideIcon } from 'lucide-react';

interface CouponStatusBadgeProps {
    coupon: Coupon;
}

const statusConfig: Record<string, { label: string; variant: BadgeProps['variant']; Icon: LucideIcon }> = {
    active: { label: 'Activo', variant: 'safe', Icon: CheckCircle },
    inactive: { label: 'Inactivo', variant: 'secondary', Icon: CircleOff },
    exhausted: { label: 'Agotado', variant: 'warning', Icon: Flame },
    expired: { label: 'Vencido', variant: 'critical', Icon: Clock },
};

export function CouponStatusBadge({ coupon }: CouponStatusBadgeProps) {
    const status = getCouponStatus(coupon);
    const { label, variant, Icon } = statusConfig[status];

    return (
        <Badge variant={variant} className="gap-1.5">
            <Icon className="h-3 w-3" />
            {label}
        </Badge>
    );
}
