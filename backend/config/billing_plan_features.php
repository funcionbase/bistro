<?php

/**
 * Mapeo de slugs de features y regimenes tributarios a labels amigables
 * en espanol, usado por App\Services\BillingPlanPresenter al renderizar
 * el cuerpo de los correos transaccionales billing.
 *
 * Slugs sin entrada se omiten silenciosamente (NO se muestra el slug crudo)
 * y se loggea via Log::warning('billing.plan_feature_label_missing', [...])
 * para detectar drift entre BillingPlanSeeder y este mapeo.
 *
 * Tono editorial: cliente final (duenio del restaurante), espanol neutro,
 * sin tecnicismos.
 */
return [
    'features' => [
        'menu' => 'Menu digital con QR para mesas',
        'orders' => 'Gestion de pedidos en sala, domicilio y para llevar',
        'reports' => 'Reportes de ventas, productos y caja',
        'coupons' => 'Cupones de descuento y promociones',
        'deliveries' => 'Modulo de domicilios con seguimiento',
        'metrics' => 'Metricas y panel de control en tiempo real',
        'api' => 'Acceso a la API publica para integraciones',
        'multi_branch' => 'Soporte para multiples sedes',
        'kds' => 'Pantalla de cocina (KDS)',
        'inventory' => 'Control de inventario y costeo',
        'crm' => 'Base de clientes y programa de fidelizacion',
        'chat' => 'Chat unificado de WhatsApp, Instagram y Facebook',
    ],

    'tax_regime_labels' => [
        // Cuando price_includes_tax = true se usa el texto generico del
        // presenter. Estos labels aplican cuando price_includes_tax = false.
        'iva_19' => '19% de IVA',
        'iva_5' => '5% de IVA',
        'iva_exento' => 'IVA exento',
        'inc_8' => '8% de INC (Impuesto al consumo)',
        'simple_no_iva' => 'Regimen Simple — sin IVA',
        'simple' => 'Regimen Simple — sin IVA',
    ],

    'billing_cycle_labels' => [
        'monthly' => 'cada mes',
        'yearly' => 'al ano',
    ],
];
