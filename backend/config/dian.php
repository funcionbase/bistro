<?php

declare(strict_types=1);

/**
 * Configuración del subsistema DIAN (HU #235).
 *
 * - `default_final_consumer`: convención DIAN UBL Colombia para "consumidor
 *   final" — adquirente genérico cuando el cajero no captura datos. NIT
 *   222222222222 es el reservado oficial.
 * - `storage_disk`: disco S3 (o s3-minio en dev) para XML/PDF. NUNCA `local`
 *   (CLAUDE.md §12 — N-instance safe).
 * - `qr_base_url`: URL pública DIAN del catálogo donde se valida el CUFE/CUDE.
 *   Producción usa `catalogo-vpfe`; habilitación usa `catalogo-vpfe-hab`.
 * - `mock`: probabilidades + latencia para `MockDianProvider`. Configurables
 *   para tests determinísticos.
 * - `tax_codes`: códigos DIAN del catálogo de impuestos. Usados en el
 *   computo del CUFE (CodImp1, CodImp2, CodImp3) y en `<cac:TaxTotal>`
 *   del XML UBL.
 * - `signs`: códigos de tipo de documento DIAN.
 */
return [

    'storage_disk' => env('DIAN_STORAGE_DISK', 's3'),

    'default_environment' => env('DIAN_DEFAULT_ENVIRONMENT', 'habilitacion'),

    /*
     * Minutos tras los cuales un documento atascado en un estado NO terminal
     * de transporte (`pending` / `sent`) se considera "stuck" y es elegible
     * para recuperación vía re-submisión al provider (reusando consecutivo).
     * Debe superar holgadamente la latencia del provider y el delay máximo del
     * webhook async (`mock.async_delay_seconds_range`) para no pisar emisiones
     * en vuelo ni webhooks que aún van a llegar. Default 15 min.
     */
    'stuck_recovery_minutes' => (int) env('DIAN_STUCK_RECOVERY_MINUTES', 15),

    'qr_base_url' => [
        'produccion' => 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey=',
        'habilitacion' => 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey=',
    ],

    'environment_codes' => [
        'produccion' => '1',
        'habilitacion' => '2',
    ],

    /**
     * Adquirente genérico DIAN para emisión "consumidor final" cuando el
     * cajero no captura adquirente y la empresa no tiene
     * `dian_default_recipients` configurado.
     */
    'default_final_consumer' => [
        'doc_type' => 'CC',
        'doc_number' => '222222222222',
        'dv' => null,
        'legal_name' => 'CONSUMIDOR FINAL',
        'email' => null,
        'address' => null,
        'municipality_dane_code' => null,
        'fiscal_responsibilities' => ['R-99-PN'],
        'recipient_type' => 'final_consumer',
    ],

    /**
     * Configuración del MockDianProvider. Suma debe dar 1.0.
     * Determinismo: el provider hashea el ID del documento como seed.
     */
    'mock' => [
        'accept_rate' => env('DIAN_MOCK_ACCEPT_RATE', 0.92),
        'reject_rate' => env('DIAN_MOCK_REJECT_RATE', 0.05),
        'async_rate' => env('DIAN_MOCK_ASYNC_RATE', 0.02),
        'error_rate' => env('DIAN_MOCK_ERROR_RATE', 0.01),
        'latency_ms_range' => [
            (int) env('DIAN_MOCK_LATENCY_MIN_MS', 150),
            (int) env('DIAN_MOCK_LATENCY_MAX_MS', 800),
        ],
        'async_delay_seconds_range' => [
            (int) env('DIAN_MOCK_ASYNC_DELAY_MIN', 5),
            (int) env('DIAN_MOCK_ASYNC_DELAY_MAX', 30),
        ],
        /** Catálogo real DIAN de razones de rechazo — para el mock. */
        'rejection_reasons_catalog' => [
            'FAJ24a' => 'NumFac duplicado',
            'FAB01' => 'Estructura UBL inválida',
            'FAB02' => 'Firma XAdES inválida',
            'FAB07' => 'CUFE/CUDE no coincide con campos canónicos',
            'FAJ02' => 'Resolución no autorizada',
            'FAJ03' => 'Consecutivo fuera de rango',
            'FAJ23' => 'Fecha de emisión fuera de vigencia',
        ],
    ],

    /**
     * Códigos DIAN del catálogo de impuestos.
     * Usados en el computo del CUFE/CUDE y en el bloque `<cac:TaxTotal>`.
     */
    'tax_codes' => [
        'iva' => '01',
        'inc' => '04',
        'ica' => '03',
    ],

    /**
     * Tipos de documento (DIAN type code).
     */
    'document_types' => [
        'invoice' => '01',                       // FEV
        'credit_note' => '91',                   // NC FEV
        'debit_note' => '92',                    // ND FEV
        'pos_equivalent' => '20',                // DEE POS
        'pos_equivalent_credit_note' => '21',    // NC DEE POS (no estándar, marca interna)
    ],

    /**
     * Documentos válidos (lista cerrada). El controller valida contra esta lista.
     */
    'document_types_allowed' => [
        'invoice',
        'credit_note',
        'debit_note',
        'pos_equivalent',
        'pos_equivalent_credit_note',
    ],

    /**
     * Catálogo DIAN de responsabilidades fiscales (subset común CO).
     * Lista completa en Anexo Técnico DIAN — agregar slugs según necesidad.
     */
    'fiscal_responsibilities_catalog' => [
        'O-13' => 'Gran contribuyente',
        'O-15' => 'Autorretenedor',
        'O-23' => 'Agente de retención IVA',
        'O-47' => 'Régimen simple de tributación',
        'R-99-PN' => 'No responsable',
    ],

    /**
     * Catálogo DIAN tipos de identificación.
     */
    'doc_types_catalog' => [
        'CC' => 'Cédula de ciudadanía',
        'CE' => 'Cédula de extranjería',
        'NIT' => 'NIT',
        'NIT_EXT' => 'NIT extranjero',
        'TI' => 'Tarjeta de identidad',
        'PA' => 'Pasaporte',
        'RC' => 'Registro civil',
    ],

    /**
     * Cuántos días antes del vencimiento de una resolución se emite alerta.
     */
    'resolution_expiration_alert_days' => env('DIAN_RESOLUTION_ALERT_DAYS', 30),

    /**
     * TTL del lock distribuido para emit endpoint (segundos).
     * Cubre worst case: latencia provider + escritura S3 + commit.
     */
    'emit_lock_ttl_seconds' => 30,

    /**
     * TTL del lock para webhook (segundos).
     */
    'webhook_lock_ttl_seconds' => 60,
];
