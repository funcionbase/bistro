import { calculateDiscount, calculateTotalAfterDiscount } from '@/lib/calculate-discount';
import { formatCurrency, formatDiscountValue } from '@/lib/coupon-helpers';
import type { Coupon } from '@/types/coupon';
import { ArrowRight } from 'lucide-react';

interface DiscountCalculatorProps {
    coupon: Coupon;
    totalAmount: number;
}

export function DiscountCalculator({ coupon, totalAmount }: DiscountCalculatorProps) {
    const discount = calculateDiscount(coupon, totalAmount);
    const totalAfter = calculateTotalAfterDiscount(coupon, totalAmount);

    return (
        <div className="bg-muted/40 flex items-center gap-2 rounded-lg px-4 py-2 text-sm">
            <span className="text-foreground font-medium tabular-nums">{formatCurrency(totalAmount)}</span>
            <span className="text-muted-foreground">−</span>
            <span className="font-semibold text-[color:var(--color-status-safe)]">{formatDiscountValue(coupon)}</span>
            <span className="text-muted-foreground text-xs tabular-nums">= {formatCurrency(discount)}</span>
            <ArrowRight className="text-muted-foreground h-3 w-3" />
            <span className="text-primary font-bold tabular-nums">{formatCurrency(totalAfter)}</span>
        </div>
    );
}
