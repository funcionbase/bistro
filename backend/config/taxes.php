<?php

declare(strict_types=1);

/**
 * Presets tributarios comunes para restaurantes en Colombia.
 *
 * Cada empresa elige un `tax_regime` y opcionalmente sobrescribe la tasa y el
 * label. El backend usa estos presets como referencia (validación) y el frontend
 * para poblar el selector de configuración.
 *
 * regimes:
 *  - simple    : Régimen Simple de Tributación → no factura IVA (tasa 0)
 *  - inc_8     : Restaurantes que aplican Impuesto Nacional al Consumo (8%)
 *  - iva_19    : Régimen común con IVA general (19%)
 *  - iva_5     : IVA reducido para algunos productos (5%)
 *  - iva_exento: Productos/servicios exentos (0% pero con derecho a devolución)
 *  - custom    : El cliente define rate y label libremente
 */
return [

    'regimes' => [
        'simple' => [
            'rate' => 0.00,
            'label' => 'Régimen Simple (sin IVA)',
        ],
        'inc_8' => [
            'rate' => 8.00,
            'label' => 'INC 8%',
        ],
        'iva_19' => [
            'rate' => 19.00,
            'label' => 'IVA 19%',
        ],
        'iva_5' => [
            'rate' => 5.00,
            'label' => 'IVA 5%',
        ],
        'iva_exento' => [
            'rate' => 0.00,
            'label' => 'IVA Exento',
        ],
        'custom' => [
            'rate' => 0.00,
            'label' => 'Personalizado',
        ],
    ],

    'available_regimes' => ['simple', 'inc_8', 'iva_19', 'iva_5', 'iva_exento', 'custom'],

];
