export type CouponType = 'percentage' | 'fixed_amount';
export type CouponStatus = 'active' | 'inactive' | 'exhausted';

export interface Coupon {
    id: string;
    company_nit: string;
    code: string;
    type: CouponType;
    value: number;
    valid_from: string | null;
    valid_until: string | null;
    max_uses: number | null;
    uses_count: number;
    min_order_amount: number;
    first_order_only: boolean;
    is_active: boolean;
    status: CouponStatus;
    valid_days: number[] | null;
    valid_hours_from: string | null;
    valid_hours_to: string | null;
    auto_apply: boolean;
    created_by: string | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    redemptions_count?: number;
    redemptions?: CouponRedemption[];
}

export interface CouponRedemption {
    id: string;
    coupon_id: string;
    company_nit: string;
    order_id: string;
    client_phone: string | null;
    discount_amount: number;
    order_total_before: number;
    order_total_after: number;
    created_at: string;
}

export interface CouponValidationResponse {
    valid: boolean;
    coupon_code?: string;
    discount_type?: CouponType;
    discount_value?: number;
    discount_amount?: number;
    original_total?: number;
    final_total?: number;
    error?: string;
    message?: string;
}

export interface CouponFormData {
    code: string;
    type: CouponType;
    value: string;
    valid_from: string;
    valid_until: string;
    max_uses: string;
    min_order_amount: string;
    first_order_only: boolean;
    valid_days: number[];
    valid_hours_from: string;
    valid_hours_to: string;
    auto_apply: boolean;
}

export interface ActiveAutoApply {
    active: boolean;
    coupon_code?: string;
    discount_type?: CouponType;
    discount_value?: number;
    discount_amount?: number;
    original_total?: number;
    final_total?: number;
    ends_at?: string | null;
    label?: string;
}

export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
