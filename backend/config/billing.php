<?php

return [
    'currency' => env('BILLING_CURRENCY', 'COP'),
    'grace_months' => (int) env('BILLING_GRACE_MONTHS', 2),
    'due_day' => (int) env('BILLING_DUE_DAY', 10),
    'generate_day' => (int) env('BILLING_GENERATE_DAY', 1),
    'generate_hour' => (int) env('BILLING_GENERATE_HOUR', 3),
    'overdue_day' => (int) env('BILLING_OVERDUE_DAY', 16),
    'overdue_hour' => (int) env('BILLING_OVERDUE_HOUR', 3),

    // Régimen fiscal de la plataforma como empresa-proveedora SaaS.
    // Define si las invoices generadas para empresas cliente desglosan IVA.
    //  - iva_19: Régimen común (default).
    //  - simple_no_iva: Régimen Simple sin IVA.
    //  - iva_5 / inc_8 / iva_exento: tarifas alternativas (raras).
    'funcionbase_tax_regime' => env('BISTRO_TAX_REGIME', 'iva_19'),
    'funcionbase_tax_rate' => (float) env('BISTRO_TAX_RATE', 19.00),

    // Plan default SaaS — slug usado por endpoint público /api/v1/billing/plans/default.
    'default_plan_slug' => env('BILLING_DEFAULT_PLAN_SLUG', 'default'),

    // Slug del promo_code que representa la prueba gratuita. Lo aplica
    // BillingService::activateCompany al activar una empresa (descuento 100% ×
    // N meses). La DURACIÓN del trial NO está acá: vive en months_duration de
    // esa fila (DB), sembrada por TrialPromoCodeSeeder. Acá solo el identificador.
    'trial_promo_code' => 'TRIAL3',

    // Precio unitario COP (IVA incluido) por documento electrónico DIAN
    // emitido en el período — cargo por uso del Plan Plus.
    'dian_unit_price' => (float) env('BILLING_DIAN_UNIT_PRICE', 10),

    // Si true, después de generar invoices mensuales se dispara EmitDianInvoiceJob
    // que crea el ElectronicDocument vinculado (CUFE + consecutivo). En dev y QA
    // queda en mock; en pdn se conecta al provider real cuando se configure.
    'emit_dian_for_invoices' => (bool) env('BILLING_EMIT_DIAN_FOR_INVOICES', true),

    /*
    |--------------------------------------------------------------------------
    | Empresa-proveedora SaaS
    |--------------------------------------------------------------------------
    | Identificación de la empresa que opera esta instancia y factura a sus
    | empresas cliente. Necesario para emitir invoices SaaS con CUFE/QR DIAN.
    | Resolución DIAN y DianProviderConfig se crean vía FuncionbaseProviderSeeder.
    */
    'bistro' => [
        // Todos vacíos por default a propósito: un valor de ejemplo se
        // mostraría como si fuera real en "Datos para transferir" y en las
        // invoices SaaS. Vacío = no se muestra en el panel, el seeder
        // skipea y la emisión DIAN SaaS lanza error explícito (guards
        // existentes) hasta que configures los datos reales de tu empresa.
        'nit' => env('BISTRO_NIT', ''),
        'dv' => env('BISTRO_DV', ''),
        'commercial_name' => env('BISTRO_COMMERCIAL_NAME', ''),
        'legal_name' => env('BISTRO_LEGAL_NAME', ''),
        'address' => env('BISTRO_ADDRESS', ''),
        'municipality_dane_code' => env('BISTRO_MUNICIPALITY_DANE', ''),
        'billing_email' => env('BISTRO_BILLING_EMAIL', ''),
        'billing_phone' => env('BISTRO_BILLING_PHONE', ''),
    ],
    'pdf_driver' => env('INVOICE_PDF_DRIVER', 'dompdf'),
    'storage_disk' => env('INVOICE_STORAGE_DISK', 'local'),
    'notify_on_generate' => (bool) env('BILLING_NOTIFY_ON_GENERATE', true),
    'notify_on_overdue' => (bool) env('BILLING_NOTIFY_ON_OVERDUE', true),
    'download_ttl' => (int) env('BILLING_DOWNLOAD_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Past-due con gracia y bloqueo total
    |--------------------------------------------------------------------------
    */

    // Meses calendario que la empresa puede operar past_due antes de pasar a
    // `suspended`. Se computa con addMonthsNoOverflow desde past_due_started_at.
    'past_due_grace_months' => (int) env('BILLING_PAST_DUE_GRACE_MONTHS', 3),

    // Fallback legacy del trial por días. El trial nuevo se modela como promo
    // 100% (ver `trial_promo_code`); esto solo aplica a empresas sin promo de
    // trial ni `paid_billing_starts_at` (registros viejos en recalculateCompanyStatus).
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 90),

    // Disco para comprobantes de pago subidos por el cliente. Retención DIAN.
    'payment_proof_disk' => env('BILLING_PAYMENT_PROOF_DISK', 's3_documents'),

    // Disco + prefijo para el CSV diario de morosos (uso interno).
    'delinquent_export_disk' => env('BILLING_DELINQUENT_EXPORT_DISK', 's3_documents'),
    'delinquent_export_prefix' => env('BILLING_DELINQUENT_EXPORT_PREFIX', 'internal/delinquent-companies'),

    // Email operativo para notificación de comprobantes subidos.
    'ops_email' => env('BILLING_OPS_EMAIL'),

    // Llave de pago del proveedor SaaS, visible al cliente bloqueado. Se
    // expone vía Inertia shared props SOLO cuando company.status ∈
    // {past_due, suspended}.
    'funcionbase_payment' => [
        'breb_key' => env('BISTRO_PAYMENT_BREB_KEY'),
        'bank_name' => env('BISTRO_PAYMENT_BANK_NAME'),
        'account_number' => env('BISTRO_PAYMENT_ACCOUNT_NUMBER'),
        'account_type' => env('BISTRO_PAYMENT_ACCOUNT_TYPE'),
        'account_holder' => env('BISTRO_PAYMENT_ACCOUNT_HOLDER'),
    ],
];
