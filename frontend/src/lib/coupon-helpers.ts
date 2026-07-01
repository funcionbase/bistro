import { formatCurrency } from '@/lib/formatters';
import type { Coupon, CouponStatus, CouponType } from '@/types/coupon';

// Re-export por compatibilidad: el canónico vive en '@/lib/formatters'.
export { formatCurrency } from '@/lib/formatters';

export function formatCouponType(type: CouponType): string {
    return type === 'percentage' ? 'Porcentaje' : 'Monto fijo';
}

export function formatDiscountValue(coupon: Coupon): string {
    if (coupon.type === 'percentage') {
        return `${coupon.value}%`;
    }
    return formatCurrency(coupon.value);
}

export function formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('es-CO', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: 'America/Bogota',
    });
}

export function validateCouponCode(code: string): string | null {
    if (!code.trim()) return 'El código es requerido';
    if (code.length < 4) return 'El código debe tener al menos 4 caracteres';
    if (code.length > 20) return 'El código no puede superar 20 caracteres';
    if (!/^[A-Z0-9_-]+$/i.test(code)) return 'Solo letras, números, guiones y guiones bajos';
    return null;
}

export function getCouponStatus(coupon: Coupon): CouponStatus | 'expired' {
    if (coupon.status === 'exhausted') return 'exhausted';
    if (coupon.status === 'inactive') return 'inactive';
    if (coupon.valid_until && new Date(coupon.valid_until) < new Date()) return 'expired';
    return 'active';
}

export function maskPhoneNumber(phone: string | null): string {
    if (!phone) return '—';
    const cleaned = phone.replace(/\D/g, '');
    if (cleaned.length <= 8) return cleaned.slice(0, 2) + '****' + cleaned.slice(-2);
    return cleaned.slice(0, 6) + '****' + cleaned.slice(-4);
}
