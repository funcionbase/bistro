export type SupplierDocumentType = 'NIT' | 'CC' | 'CE' | 'PAS' | 'OTRO';

export interface Supplier {
    id: string;
    name: string;
    document_type: SupplierDocumentType | null;
    document_number: string | null;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    payment_terms_days: number;
    notes: string | null;
    archived_at: string | null;
    created_at: string | null;
}

export interface SupplierFormPayload {
    name: string;
    document_type?: SupplierDocumentType | null;
    document_number?: string | null;
    contact_name?: string | null;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
    payment_terms_days?: number;
    notes?: string | null;
}

export interface SupplierListResponse {
    data: Supplier[];
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}
