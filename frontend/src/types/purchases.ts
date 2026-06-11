export type PurchaseStatus = 'draft' | 'pending' | 'received' | 'paid' | 'cancelled' | 'voided';
export type PurchasePaymentMethod = 'cash' | 'card' | 'transfer';
export type PurchaseAttachmentType = 'invoice' | 'delivery_note' | 'payment_proof' | 'other';

export interface PurchaseOrderItem {
    id: string;
    ingredient_id: string;
    description: string;
    unit?: string | null;
    quantity: string;
    unit_cost: string;
    tax_rate: string;
    tax_amount: string;
    line_total: string;
}

export interface PurchaseOrderAttachment {
    id: string;
    type: PurchaseAttachmentType;
    original_name: string;
    mime: string;
    size_bytes: number;
    created_at: string | null;
}

export interface PurchaseCreditNoteSummary {
    id: string;
    code: string;
    reason: string;
    total_reversed: string;
    created_at: string | null;
}

export interface PurchaseOrderSummary {
    id: string;
    code: string;
    status: PurchaseStatus;
    supplier: { id: string; name: string } | null;
    expected_date: string | null;
    received_date: string | null;
    paid_date: string | null;
    subtotal: string;
    tax_amount: string;
    total: string;
    payment_method: PurchasePaymentMethod | null;
    pending_supplier_refund: boolean;
    created_at: string | null;
}

export interface PurchaseOrderDetail extends PurchaseOrderSummary {
    notes: string | null;
    payment_reference: string | null;
    voided_at: string | null;
    items: PurchaseOrderItem[];
    attachments: PurchaseOrderAttachment[];
    credit_notes: PurchaseCreditNoteSummary[];
}

export interface PurchaseOrderListResponse {
    data: PurchaseOrderSummary[];
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

export interface PurchaseOrderItemPayload {
    ingredient_id: string;
    quantity: number | string;
    unit_cost: number | string;
    tax_rate?: number | string;
}

export interface PurchaseOrderCreatePayload {
    supplier_id: string;
    expected_date?: string | null;
    notes?: string | null;
    items: PurchaseOrderItemPayload[];
}

export interface PurchaseOrderUpdatePayload {
    expected_date?: string | null;
    notes?: string | null;
    items?: PurchaseOrderItemPayload[];
}

export interface PurchaseFilters {
    q: string;
    status: PurchaseStatus | '';
    supplier_id: string | null;
    pending_refund: boolean;
}

export const STATUS_LABELS: Record<PurchaseStatus, string> = {
    draft: 'Borrador',
    pending: 'Confirmada',
    received: 'Recibida',
    paid: 'Pagada',
    cancelled: 'Cancelada',
    voided: 'Anulada',
};

export const PAYMENT_LABELS: Record<PurchasePaymentMethod, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
};

export const ATTACHMENT_LABELS: Record<PurchaseAttachmentType, string> = {
    invoice: 'Factura',
    delivery_note: 'Remisión',
    payment_proof: 'Soporte de pago',
    other: 'Otro',
};
