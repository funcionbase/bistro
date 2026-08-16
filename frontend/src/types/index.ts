import type { CompanyStatus } from '@/lib/company-status';
import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url?: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    permission?: string;
    /** Visible si el usuario tiene AL MENOS UNO de estos permisos (OR). */
    anyPermission?: string[];
    comingSoon?: boolean;
    /** Contador rojo a la derecha del item (ej. conversaciones sin responder). 0 = sin badge. */
    badge?: number;
    children?: NavItem[];
    /**
     * Capability del vertical de la sede activa (#237). Si está definida y la
     * sede activa no la habilita, el item se oculta y, cuando un grupo padre
     * queda sin hijos visibles, también se oculta el grupo. A diferencia del
     * `permission`, esta no es escalable por el usuario — depende del tipo de
     * negocio de la sede (ej. `dark_store` no tiene `tables`).
     *
     * Mapa canónico: ver `App\Services\BusinessCapabilityService::DEFAULT_FLAGS`.
     */
    businessCapability?: string;
}

export interface CompanySettings {
    timezone: string;
    currency: string;
    currency_symbol: string;
    language: string;
    order_auto_confirm: boolean;
    order_notify_customer_email: boolean;
    bot_welcome_message: string;
    bot_away_message: string;
    delivery_area_km: number;
    min_order_amount: number;
    payment_methods: string[];
    payment_method_accounts: Record<string, string>;
    whatsapp_read_receipts: boolean;
    /** Respuesta automática fuera de horario, sin n8n (§8.4b punto 10). Off por defecto. */
    whatsapp_away_reply_enabled: boolean;
    food_cost_alert_threshold: string;
    'loyalty.enabled': boolean;
    'loyalty.points_per_cop': string;
}

export interface Company {
    // PK interna (UUID). Opcional por ahora: el frontend sigue identificando
    // empresas por `nit` en JWT, URLs públicas y payloads internos. Se expone
    // por si algún consumidor futuro la necesita para anti-enumeración o
    // logging interno desacoplado del NIT.
    id?: string;
    nit: string;
    name: string;
    status: CompanyStatus;
    logo_url?: string | null;
    linked?: boolean;
    brand_color?: string | null;
    // Snapshot tributario provisto por el endpoint de bootstrap de la SPA.
    tax_regime?: 'simple' | 'inc_8' | 'iva_19' | 'iva_5' | 'iva_exento' | 'custom';
    default_tax_rate?: number;
    default_tax_label?: string;
    tax_included_in_price?: boolean;
    // Snapshot de past_due (#175) — solo cuando status ∈ {past_due, suspended}.
    past_due_started_at?: string | null;
    expected_block_at?: string | null;
    payment_blocked_at?: string | null;
    // Features del plan de facturación ACTIVO (#facturación-dian). `[]` si no
    // hay subscription activa. Ej: incluye `'dian'` solo en Plan Plus — gatea
    // el sidebar y /company/dian sin un fetch extra a /billing/subscription.
    plan_features?: string[];
    funcionbase_payment?: {
        breb_key: string | null;
        bank_name: string | null;
        account_number: string | null;
        account_type: string | null;
        account_holder: string | null;
        // #246 — Identificación fiscal de bistro visible al cliente para
        // diligenciar la transferencia (NIT/DV en formulario del banco, etc.).
        nit: string | null;
        dv: string | null;
        legal_name: string | null;
        commercial_name: string | null;
        billing_email: string | null;
        billing_phone: string | null;
    };
}

export interface BillingPlan {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    price: number;
    currency: string;
    billing_cycle: 'monthly' | 'annual' | string;
    features: string[] | null;
    is_active: boolean;
}

export interface Subscription {
    id: string;
    company_nit: string;
    billing_plan_id: string;
    status: 'active' | 'cancelled' | 'suspended' | string;
    starts_at: string;
    ends_at: string | null;
    plan: BillingPlan;
}

export interface InvoiceLine {
    id: string;
    description: string;
    quantity: number;
    unit_price: number;
    subtotal: number;
}

export interface InvoicePayment {
    id: string;
    amount: number;
    currency: string;
    payment_date: string;
    payment_reference: string;
    payment_method: string | null;
}

export type InvoiceStatus = 'pending' | 'paid' | 'overdue' | 'voided';

export interface Invoice {
    id: string;
    company_nit?: string;
    subscription_id?: string;
    type: 'monthly' | 'proration' | string;
    period_from: string;
    period_to: string;
    days_billed: number;
    base_amount: number;
    discount_percent: number | null;
    discount_amount: number | null;
    amount: number;
    currency: string;
    due_date: string;
    generated_at?: string;
    status: InvoiceStatus;
    pdf_path?: string | null;
    lines?: InvoiceLine[];
    payments?: InvoicePayment[];
    subscription?: Subscription;
}

export interface InvoicePagination {
    data: Invoice[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface DianUsageResolution {
    resolution_id: string;
    prefix: string | null;
    resolution_number: string | null;
    document_type: string | null;
    count: number;
}

/** Detalle de uso DIAN del período en curso — solo presente en planes con módulo DIAN (Plan Plus). */
export interface DianUsage {
    period_from: string;
    period_to: string;
    unit_price: number;
    total_documents: number;
    usage_amount: number;
    plan_amount: number;
    estimated_total: number;
    resolutions: DianUsageResolution[];
}

export interface BillingSubscriptionData {
    subscription: Subscription | null;
    overdue_total: number;
    earliest_overdue_date: string | null;
    dian_usage: DianUsage | null;
}

export interface Bank {
    id: string;
    name: string;
}

/** Catálogo de impresión (config/printing.php). Emitido por bootstrap (#220). */
export interface PrintingConfig {
    types: Record<string, string>;
    connections: Record<string, string>;
    paper_widths: number[];
}

export type TaxRegime = 'simple' | 'inc_8' | 'iva_19' | 'iva_5' | 'iva_exento' | 'custom';

export interface TaxPreset {
    rate: number;
    label: string;
}

export interface CompanyDetail {
    id?: string;
    nit: string;
    commercial_name: string;
    legal_name: string;
    status: string;
    bank_id: string | null;
    bank_name: string | null;
    account_number: string | null;
    account_type: string | null;
    breb_key: string | null;
    qr_code_path: string | null;
    qr_code_url: string | null;
    logo_path: string | null;
    logo_url: string | null;
    // Configuración tributaria parametrizable (CO).
    tax_regime?: TaxRegime;
    default_tax_rate?: number;
    default_tax_label?: string;
    tax_included_in_price?: boolean;
}

export interface Order {
    id: string;
    company_nit: string;
    status: OrderStatus;
    total: number;
    cost?: number | null;
    ordered_at: string;
}

export interface ReportSummary {
    total_orders: number;
    completed: number;
    failed?: number;
    cancelled: number;
    refunded?: number;
    abandoned: number;
    total_revenue: number;
    total_refunded?: number;
    net_revenue?: number;
}

export type OrderStatus =
    | 'pending_approval'
    | 'pending'
    | 'in_kitchen'
    | 'ready'
    | 'in_transit'
    | 'completed'
    | 'failed'
    | 'cancelled'
    | 'refunded'
    | 'abandoned';

export type OrderStatusCategory = 'pre_operational' | 'operational' | 'terminal_success' | 'terminal_failure';

export interface OrderStatusesConfig {
    all: OrderStatus[];
    operational: OrderStatus[];
    terminal_success: OrderStatus[];
    terminal_failure: OrderStatus[];
    kanban: OrderStatus[];
    revenue: OrderStatus[];
    labels: Record<OrderStatus, string>;
    badges: Record<OrderStatus, string>;
    category: Record<OrderStatus, OrderStatusCategory>;
}

export interface Branch {
    id: string;
    name: string;
    slug: string;
    is_default: boolean;
    address?: string | null;
    city?: string | null;
    menu_qr_token?: string | null;
    /** Fee de domicilio de la sede (solo viene en activeBranch del bootstrap). */
    delivery_fee?: number | null;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    token?: string | null;
    activeCompany?: Company | null;
    companies?: Company[];
    activeBranch?: Branch | null;
    branches?: Branch[];
    needsProfileCompletion?: boolean;
    role?: { id: string; name: string; is_system: boolean } | null;
    permissions?: string[];
    orderStatuses?: OrderStatusesConfig;
    paymentMethods?: PaymentMethodsConfig;
    rbacActions?: RbacActionDescriptor[];
    employeeStatuses?: EmployeeStatusesConfig;
    /** #149 — Clave pública VAPID para Web Push. NO es secreta. */
    vapidPublicKey?: string | null;
    /** Measurement ID de GA4 (`G-XXXXXXXXXX`). Público, NO secreto. `null` => GA4 off. */
    gaMeasurementId?: string | null;
    /** #220 — catálogo de impresión, antes prop server-side de company/printers. */
    printingConfig?: PrintingConfig;
    /** #220 — bancos disponibles, antes prop server-side de company/settings. */
    availableBanks?: Bank[];
    /** Versión del backend que respondió el bootstrap (runtime, no build). */
    versions?: { backend: string };
    [key: string]: unknown;
}

/**
 * Acción RBAC canónica (#203 — `config/rbac.php`). Las columnas reales
 * (`can_create | can_read | can_update | can_delete`) viven en la BD;
 * este descriptor solo centraliza el label en es-CO para UI.
 */
export type RbacActionKey = 'can_create' | 'can_read' | 'can_update' | 'can_delete';

export interface RbacActionDescriptor {
    key: RbacActionKey;
    label: string;
}

/**
 * Catálogo de métodos de pago (#203 — `config/payments.php`).
 *
 * - `methods`: opciones seleccionables al cobrar (cash/card/transfer).
 * - `receipt_methods`: superset que puede aparecer en `payment_receipts.payment_method`
 *   (incluye `refund` que el sistema inserta automáticamente).
 * - `labels`: etiquetas es-CO para UI/PDFs.
 * - `requires_reference`: métodos que exigen `reference` en backend
 *   (CLAUDE.md §13: card/transfer siempre, cash opcional).
 */
export type PaymentMethod = 'cash' | 'card' | 'transfer' | 'nequi' | 'daviplata';
export type PaymentReceiptMethod = 'cash' | 'card' | 'transfer' | 'refund';

export interface PaymentMethodsConfig {
    methods: PaymentMethod[];
    receipt_methods: PaymentReceiptMethod[];
    labels: Partial<Record<PaymentMethod | 'refund', string>>;
    requires_reference: (PaymentMethod | 'refund')[];
}

/**
 * Catálogo de vinculation_status para colaboradores (#204 — `config/employees.php`).
 *
 * - `statuses`: lista cerrada aceptada por el enum BD.
 * - `absence_statuses`: subset que requiere `valid_from`/`valid_until` y dispara
 *   la cascada de cancelación de turnos.
 * - `labels`: etiquetas es-CO para UI/PDFs.
 * - `badges`: variante del Badge primitive a usar por estado.
 */
export type EmployeeStatus = 'active' | 'inactive' | 'vacation' | 'sick_leave' | 'compensatory';
export type EmployeeStatusBadge = 'safe' | 'warning' | 'critical' | 'secondary';

export interface EmployeeStatusesConfig {
    statuses: EmployeeStatus[];
    absence_statuses: EmployeeStatus[];
    labels: Record<EmployeeStatus, string>;
    badges: Record<EmployeeStatus, EmployeeStatusBadge>;
}

export interface User {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    cedula?: string | null;
    first_name?: string | null;
    last_name?: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
}

export interface Feature {
    id: string;
    slug: string;
    name: string;
    description: string | null;
    group: string | null;
    /** Si es true, la feature NO es asignable a roles no-sistema (solo dueño). El editor la deshabilita. */
    is_owner_only?: boolean;
}

export interface CompanyRolePermission {
    id: string;
    company_role_id: string;
    feature_id: string;
    can_create: boolean;
    can_read: boolean;
    can_update: boolean;
    can_delete: boolean;
    feature?: Feature;
}

export interface CompanyRole {
    id: string;
    company_nit: string;
    name: string;
    description: string | null;
    is_system: boolean;
    color?: string | null;
    permissions?: CompanyRolePermission[];
    users_count?: number;
}

export interface MenuItem {
    id: string;
    name: string;
    description: string | null;
    price: number;
    /** Costo unitario opcional (food cost). null = no registrado. NUNCA expuesto al menú público. */
    cost?: number | null;
    image_path: string | null;
    image_url: string | null;
    available: boolean;
    order: number;
    /** Override de tasa tributaria por ítem. null/ausente = hereda companies.default_tax_rate. */
    tax_rate?: number | null;
    /** Etiqueta legible del impuesto del ítem (ej. "IVA 19%"). */
    tax_label?: string | null;
    /** `recipe` cuando el costo viene de la BOM (read-only en UI), `manual` cuando es legacy. */
    cost_source?: 'recipe' | 'manual';
    /** Indica si el ítem tiene receta (BOM) activa registrada. */
    has_recipe?: boolean;
}

export interface MenuCategory {
    id: string;
    name: string;
    description: string | null;
    order: number;
    /**
     * #115 — Estación KDS a la que se enrutan los items de la categoría
     * cuando se aprueban. null = usar la estación `is_default=true` de la
     * sede como fallback. La existencia de la estación se valida server-side
     * contra la sede activa del request.
     */
    kds_station_id: string | null;
    items: MenuItem[];
}

export interface KdsStation {
    id: string;
    slug: string;
    name: string;
    color: string;
    sla_warn_minutes: number;
    sla_alert_minutes: number;
    is_default: boolean;
}

export interface MenuStructure {
    version: number;
    categories: MenuCategory[];
}

export interface RestaurantMenu {
    id: string;
    company_nit: string;
    name: string;
    description: string | null;
    status: 'active' | 'draft' | 'scheduled';
    active_days: number[] | null;
    structure: MenuStructure;
    created_at: string;
    updated_at: string;
}

export type UserStatus = 'active' | 'pending_enrollment' | 'inactive';

export interface CompanyMember {
    id: string;
    company_nit: string;
    user_id: string;
    company_role_id: string;
    status: 'active' | 'inactive';
    user: {
        id: string;
        name: string;
        email: string;
        status: UserStatus | string;
        avatar_url?: string | null;
    };
    role: CompanyRole;
    /** Multi-sede (#117): UUIDs de sedes accesibles dentro de la empresa activa. */
    branch_ids?: string[];
}

/**
 * Invitación pendiente — la API solo devuelve las pending no expiradas
 * (status='pending' AND expires_at > now()). El frontend la usa en
 * /users para mostrar la sección "Invitaciones pendientes" con
 * acciones reenviar/cancelar.
 */
export interface PendingInvitation {
    id: string;
    email: string;
    role_id: string;
    role_name: string | null;
    role_color: string | null;
    /** ISO 8601 datetime; null si la invitación nunca expira (raro). */
    expires_at: string | null;
    /** Último timestamp ISO 8601 en que el job procesó el envío. null si el correo aún no se mandó. */
    email_sent_at: string | null;
    created_at: string | null;
}

// --- Metrics types ---

export interface MetricKpis {
    date: string;
    total_orders: number;
    total_revenue: number;
    completed_orders: number;
    cancelled_orders: number;
    abandoned_orders: number;
    average_ticket: number;
    active_orders: number;
    active_orders_breakdown: {
        pending: number;
        in_kitchen: number;
        ready: number;
        in_transit: number;
    };
}

export interface MetricActiveOrders {
    pending: number;
    in_kitchen: number;
    ready: number;
    in_transit: number;
}

export interface MetricTopDish {
    dish_id: string;
    dish_name: string;
    times_ordered: number;
    revenue: number;
    percentage_of_total: number;
}

export interface MetricTopDishes {
    period: string;
    top_dishes: MetricTopDish[];
    total_unique_dishes: number;
}

export interface MetricAbandonment {
    period: string;
    total_cart_sessions: number;
    active_sessions: number;
    abandoned_sessions: number;
    abandonment_rate: number;
    estimated_lost_revenue: number;
}

export interface MetricHeatmapHour {
    hour: number;
    orders_count: number;
    revenue: number;
    intensity: number;
}

export interface MetricWeeklyHeatmapCell {
    day: number;
    hour: number;
    orders: number;
    revenue: number;
}

export interface MetricWeeklyHeatmap {
    period: string;
    date_from: string;
    date_to: string;
    timezone: string;
    cells: MetricWeeklyHeatmapCell[];
    max_orders: number;
}

export interface MetricHeatmap {
    period: string;
    timezone: string;
    hours: MetricHeatmapHour[];
    current_hour: number;
    peak_hour: number | null;
    peak_hour_orders: number;
}

export type MetricPeriod = 'today' | 'week' | 'month' | 'custom';

export interface MetricSummaryComparison {
    period_label: string;
    total_orders: number;
    completed_orders: number;
    cancelled_orders: number;
    abandoned_carts: number;
    total_revenue: number;
    average_ticket: number;
}

export interface MetricSummary {
    period: string;
    date_from: string;
    date_to: string;
    total_orders: number;
    completed_orders: number;
    cancelled_orders: number;
    abandoned_carts: number;
    total_revenue: number;
    average_ticket: number;
    orders_in_progress: number;
    comparison: MetricSummaryComparison | null;
}

export interface MetricTopItem {
    name: string;
    count: number;
    revenue: number;
}

export interface MetricTopItems {
    period: string;
    date_from: string;
    date_to: string;
    limit: number;
    items: MetricTopItem[];
    total_unique_items: number;
}

export interface MetricDishMarginItem {
    item_id: string;
    name: string;
    units_sold: number;
    avg_price: number;
    avg_cost: number;
    gross_revenue: number;
    gross_cost: number;
    margin_amount: number;
    margin_pct: number;
}

export interface MetricDishMargin {
    period: string;
    date_from: string;
    date_to: string;
    limit: number;
    items: MetricDishMarginItem[];
    total_unique_items: number;
}

export interface MetricCartAbandonment {
    period: string;
    date_from: string;
    date_to: string;
    total_initiated: number;
    converted: number;
    abandoned: number;
    conversion_rate: number;
    estimated_lost_revenue: number;
}

// --- Food cost (issue #113) ---

export interface FoodCostItem {
    item_id: string;
    name: string;
    units_sold: number;
    units_with_cost: number;
    avg_price: number;
    avg_cost: number | null;
    gross_revenue: number;
    gross_cost: number;
    margin_pct: number | null;
    cost_ratio: number | null;
    has_cost: boolean;
}

export interface FoodCostTotals {
    gross_revenue: number;
    gross_revenue_with_cost: number;
    gross_cost: number;
    cost_ratio_pct: number | null;
    margin_pct: number | null;
    units_sold: number;
    units_with_cost: number;
    coverage_pct: number;
}

export interface FoodCostSnapshotMeta {
    last_snapshot_at: string | null;
    items_snapshotted: number;
    scheduler_lag_hours: number | null;
}

export interface FoodCostSummary {
    period: string;
    date_from: string;
    date_to: string;
    totals: FoodCostTotals;
    items: FoodCostItem[];
    snapshot_meta: FoodCostSnapshotMeta;
}

export interface FoodCostHistoryPoint {
    date: string;
    cost: number;
    source: 'recipe' | 'manual';
}

export interface FoodCostHistory {
    menu_item_id: string;
    name: string | null;
    category: string | null;
    archived: boolean;
    period: string;
    date_from: string;
    date_to: string;
    points: FoodCostHistoryPoint[];
}

// --- Menu engineering (issue #114) ---

export type MenuEngineeringQuadrant = 'star' | 'cow' | 'puzzle' | 'dog';

export interface MenuEngineeringDish {
    item_id: string;
    name: string;
    units_sold: number;
    avg_price: number;
    avg_cost: number;
    contribution_margin: number;
    gross_revenue: number;
    popularity_pct: number;
    total_contribution: number;
    quadrant: MenuEngineeringQuadrant;
    recommendation: string;
}

export interface MenuEngineeringSummary {
    stars: number;
    cows: number;
    puzzles: number;
    dogs: number;
    unknown: number;
    classifiable: number;
    total_units: number;
    unknown_units: number;
}

export interface MenuEngineeringMatrix {
    period: string;
    date_from: string;
    date_to: string;
    thresholds: {
        popularity_pct: number;
        contribution_margin: number;
    };
    summary: MenuEngineeringSummary;
    dishes: MenuEngineeringDish[];
}

// --- Delivery types ---

export type DeliveryStatus = 'pending' | 'completed' | 'cancelled';

/**
 * Catálogo canónico de razones de cambio de estado (#203 — `DeliveryService::REASON_*`).
 *
 * - `error_usuario`: el domiciliario marcó completed por error y revirtió.
 * - `pedido_rechazado`: el cliente rechazó la entrega al recibirla.
 * - `reassigned`: la entrega se transfirió a otro domiciliario (vive sólo en
 *   `delivery_status_logs.reason`, NO en `deliveries.status_change_reason`).
 *
 * Ver `bistro/backend/constants/DELIVERY_STATUSES.md` para reglas de aplicación.
 */
export type DeliveryReason = 'error_usuario' | 'pedido_rechazado' | 'reassigned';

/** Subset que puede vivir en `deliveries.status_change_reason` (NO incluye `reassigned`). */
export type DeliveryRowReason = Extract<DeliveryReason, 'error_usuario' | 'pedido_rechazado'>;

export const DELIVERY_REASON_LABELS: Record<DeliveryReason, string> = {
    error_usuario: 'Marcada por error y revertida',
    pedido_rechazado: 'El cliente rechazó la entrega',
    reassigned: 'Reasignada a otro domiciliario',
};

export interface DeliveryUser {
    id: string;
    name: string;
    email: string;
}

export interface DeliveryOrder {
    id: string;
    status: string;
    total: number | string;
    client_phone: string | null;
}

export interface Delivery {
    id: string;
    company_nit: string;
    order_id: string;
    user_id: string;
    assigned_at: string;
    delivered_at: string | null;
    duration_minutes: number | null;
    status: DeliveryStatus;
    previous_delivery_id: string | null;
    reason: string | null;
    cancellation_reason: string | null;
    /** #119: motivo estructurado del último cambio de status (subset que puede vivir en la fila). */
    status_change_reason?: DeliveryRowReason | null;
    created_by: string | null;
    order?: DeliveryOrder;
    deliverer?: DeliveryUser;
    creator?: DeliveryUser;
}

export interface AvailableDeliverer {
    id: string;
    name: string;
    email: string;
    active_deliveries_count: number;
    daily_completed_count: number;
}

export interface DeliveryPagination {
    data: Delivery[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

export interface Courier {
    id: string;
    name: string;
    email: string;
    active_deliveries_count: number;
    daily_completed_count: number;
    available: boolean;
}

export interface DeliveryMetric {
    user_id: string;
    courier_name: string;
    total_deliveries: number;
    completed: number;
    cancelled: number;
    average_duration_minutes: number | null;
    success_rate: string;
}

export type Period = 'today' | 'week' | 'month';

export interface DashboardPageProps {
    token?: string | null;
    activeCompany?: Company | null;
    companyStatus?: string;
    needsProfileCompletion?: boolean;
    period: Period;
    // Deferred — undefined mientras carga, null si no tiene permiso
    summary?: MetricSummary | null;
    heatmap?: MetricHeatmap | null;
    abandonment?: MetricCartAbandonment | null;
    deliveries?: DeliveryMetric[] | null;
    lowStockInventory?: LowStockInventorySummary | null;
}

/** Respuesta del endpoint GET /api/v1/dashboard (#220, shell SPA). */
export interface DashboardData {
    period: Period;
    needsProfileCompletion: boolean;
    summary: MetricSummary | null;
    heatmap: MetricHeatmap | null;
    abandonment: MetricCartAbandonment | null;
    deliveries: DeliveryMetric[] | null;
    lowStockInventory: LowStockInventorySummary | null;
}

export interface LowStockInventoryItem {
    id: string;
    name: string;
    unit: string;
    current_stock: string;
    min_stock: string;
}

export interface LowStockInventorySummary {
    count: number;
    items: LowStockInventoryItem[];
}

export interface DraggedItem {
    id: string;
    type: 'category' | 'item';
    categoryId?: string;
}

export interface MenuDragState {
    activeId: string | null;
    overId: string | null;
}
