import type { Coupon } from '@/types/coupon';

export function calculateDiscount(coupon: Coupon, totalAmount: number): number {
    if (coupon.type === 'percentage') {
        return Math.round((coupon.value / 100) * totalAmount * 100) / 100;
    }
    return Math.min(coupon.value, totalAmount);
}

export function calculateTotalAfterDiscount(coupon: Coupon, totalAmount: number): number {
    return Math.max(0, totalAmount - calculateDiscount(coupon, totalAmount));
}
