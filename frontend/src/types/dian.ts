/**
 * Tipos TS espejo de los endpoints DIAN del backend (HU #235).
 *
 * Espejo manual de `App\Http\Resources\Dian\*` — actualizar en el mismo PR
 * cuando agreguen/cambien campos.
 */

export type DianDocumentType =
    | 'invoice'
    | 'credit_note'
    | 'debit_note'
    | 'pos_equivalent'
    | 'pos_equivalent_credit_note';

export type DianDocumentStatus =
    | 'pending'
    | 'queued'
    | 'sent'
    | 'accepted'
    | 'rejected'
    | 'error'
    | 'needs_recipient_data';

export type DianEnvironment = 'habilitacion' | 'produccion';

export type DianDocTypeCode = 'CC' | 'CE' | 'NIT' | 'NIT_EXT' | 'TI' | 'PA' | 'RC';

export type DianRecipientType = 'person' | 'company' | 'final_consumer';

export interface DianElectronicDocument {
    id: string;
    company_nit: string;
    branch_id: string;
    order_id: string | null;
    /** Resolución DIAN a la que quedó ligado el documento (sumó a su conteo). */
    dian_resolution_id: string;
    document_type: DianDocumentType;
    prefix: string;
    consecutive: number;
    full_number: string;
    unique_code: string;
    unique_code_type: 'cufe' | 'cude';
    status: DianDocumentStatus;
    environment: DianEnvironment | null;
    provider_slug: string | null;
    provider_track_id: string | null;
    rejection_reason: string | null;
    retry_count: number;
    qr_data: string | null;
    /** True si el PDF está disponible en S3. false para docs sembrados o sin blob. */
    has_pdf: boolean;
    /** True si el XML UBL está disponible en S3. */
    has_xml: boolean;
    issued_at: string | null;
    sent_at: string | null;
    accepted_at: string | null;
    rejected_at: string | null;
    last_retry_at: string | null;
    references_document_id: string | null;
    created_at: string | null;
}

export interface DianResolution {
    id: string;
    document_type: DianDocumentType;
    prefix: string;
    range_from: number;
    range_to: number;
    current_number: number;
    resolution_number: string;
    valid_from: string | null;
    valid_until: string | null;
    environment: DianEnvironment;
    is_active: boolean;
    is_expiring_soon: boolean;
    is_exhausted: boolean;
}

export interface DianProviderConfig {
    id: string;
    provider_slug: string;
    api_base_url: string | null;
    software_id: string | null;
    test_set_id: string | null;
    environment: DianEnvironment;
    is_active: boolean;
    has_api_token: boolean;
    has_software_pin: boolean;
    has_webhook_secret: boolean;
}

export interface DianDefaultRecipient {
    doc_type: DianDocTypeCode;
    doc_number: string;
    dv: string | null;
    legal_name: string;
    email: string | null;
    address: string | null;
    municipality_dane_code: string | null;
    fiscal_responsibilities: string[];
    applies_to_auto_emit_only: boolean;
}

export interface DianFiscalProfile {
    nit: string;
    dv: string | null;
    commercial_name: string;
    legal_name: string;
    legal_representative_name: string | null;
    legal_representative_doc_type: DianDocTypeCode | null;
    legal_representative_doc_number: string | null;
    economic_activity_code: string | null;
    fiscal_responsibilities: string[];
    tax_obligations: string[];
    municipality_dane_code: string | null;
    billing_email: string | null;
    billing_phone: string | null;
    physical_address: string | null;
    country_code: string;
}

export interface DianFiscalProfileResponse {
    data: DianFiscalProfile;
    catalogs: {
        doc_types: Record<string, string>;
        fiscal_responsibilities: Record<string, string>;
    };
    /** Si el rol activo puede editar el perfil fiscal (gate company.fiscal_profile,update). */
    can_update: boolean;
}

export interface DianRecipientMatch {
    id: string;
    phone: string | null;
    name: string | null;
    doc_type: DianDocTypeCode | null;
    doc_number: string | null;
    dv: string | null;
    legal_name: string | null;
    email: string | null;
    address: string | null;
    municipality_dane_code: string | null;
    fiscal_responsibilities: string[];
    dian_complete: boolean;
}

/**
 * Refactor #235: el lookup retorna SIEMPRE un array de matches porque un phone
 * puede pertenecer a varios miembros de una familia. `match_type='phone'` →
 * potencialmente N resultados (UI muestra selector); `match_type='doc'` → 0 o 1
 * (UNIQUE parcial por empresa).
 */
export interface DianRecipientLookup {
    data: DianRecipientMatch[];
    match_type: 'phone' | 'doc';
}

export const DIAN_DOC_TYPE_LABELS: Record<DianDocumentType, string> = {
    invoice: 'Factura electrónica',
    credit_note: 'Nota crédito FEV',
    debit_note: 'Nota débito FEV',
    pos_equivalent: 'DEE POS',
    pos_equivalent_credit_note: 'Nota crédito POS',
};

export const DIAN_STATUS_LABELS: Record<DianDocumentStatus, string> = {
    pending: 'Pendiente',
    queued: 'En cola',
    sent: 'Enviado',
    accepted: 'Aceptado',
    rejected: 'Rechazado',
    error: 'Error',
    needs_recipient_data: 'Faltan datos del cliente',
};
