export type InvoiceStatus = 'pending' | 'paid' | 'overdue' | 'voided';
export type InvoiceType = 'monthly' | 'proration';
export type SubscriptionStatus = 'active' | 'cancelled' | 'suspended';
// `CompanyBillingStatus` retirado por #205 — usar `CompanyStatus` desde
// `@/lib/company-status`. El alias era código muerto (sin consumers).

export interface BillingPlan {
    id: string;
    name: string;
    price: number;
    currency: string;
    billing_cycle: 'monthly' | 'annual' | string;
}

export interface ActiveSubscription {
    id: string;
    status: SubscriptionStatus;
    starts_at: string;
    ends_at: string | null;
    plan: BillingPlan;
}

export interface InvoicePayment {
    amount: number;
    currency: string;
    payment_date: string;
    payment_reference: string;
    payment_method: string | null;
}

export interface InvoiceLine {
    description: string;
    unit_price: number;
    subtotal: number;
}

export interface BillingInvoice {
    id: string;
    type: InvoiceType;
    period_from: string;
    period_to: string;
    days_billed: number;
    base_amount: number;
    discount_percent: number | null;
    discount_amount: number | null;
    amount: number;
    currency: string;
    due_date: string;
    status: InvoiceStatus;
    generated_at: string | null;
    subscription?: { plan: { name: string } | null } | null;
    payments?: InvoicePayment[];
    lines?: InvoiceLine[];
}

export interface PaginatedInvoices {
    data: BillingInvoice[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface BillingSubscriptionResponse {
    subscription: ActiveSubscription | null;
    overdue_total: number;
    earliest_overdue_date: string | null;
}
