<?php

/**
 * Configuración del programa de fidelización (#122).
 *
 * Estos valores son defaults globales. Cada empresa puede overridearlos en
 * company_settings con keys:
 *  - loyalty.enabled (boolean)
 *  - loyalty.points_per_cop (string decimal o float)
 *  - loyalty.tiers (json — mismo shape que 'tiers' abajo)
 *  - loyalty.refund_reverses_points (boolean)
 *  - loyalty.expire_after_months (integer; 0 o null desactiva)
 *
 * Los multiplicadores aplican al EARN: gold gana 1.4x los puntos por la misma
 * compra. El canje (redeem) consume puntos al valor literal del catálogo.
 *
 * Reglas contables (CLAUDE.md):
 *  - Puntos NO son moneda. Nunca convertir 1 pt = $X en payment_receipts.
 *  - El canje genera un Coupon (fixed_amount o percentage) que SÍ afecta
 *    orders.discount_amount al aplicarse.
 */
return [
    'enabled' => (bool) env('LOYALTY_ENABLED', false),

    // Tasa base de acumulación: puntos otorgados por cada 1 COP. 0.001 = 1 pt
    // cada $1.000. El multiplicador de tier escala este valor en el earn.
    'points_per_cop' => (float) env('LOYALTY_POINTS_PER_COP', 0.001),

    // Tiers ordenados ascendentemente por min_lifetime. La función
    // LoyaltyService::tierFor() recorre el array y devuelve el último tier
    // cuyo min_lifetime <= lifetime_earned.
    'tiers' => [
        'bronze' => ['min_lifetime' => 0,     'earn_multiplier' => 1.0],
        'silver' => ['min_lifetime' => 5000,  'earn_multiplier' => 1.2],
        'gold' => ['min_lifetime' => 15000, 'earn_multiplier' => 1.4],
    ],

    // Catálogo de recompensas disponibles para canje. reward_key es la
    // identidad estable; cambiar el costo de un key existente es válido
    // (no muta redemptions pasadas, que tienen points snapshot).
    'rewards' => [
        'pts_500_off_5k' => [
            'points' => 500,
            'discount_type' => 'fixed_amount',
            'discount_value' => 5000,
            'min_order_amount' => 10000,
            'label' => '$5.000 de descuento',
        ],
        'pts_1000_off_10k' => [
            'points' => 1000,
            'discount_type' => 'fixed_amount',
            'discount_value' => 10000,
            'min_order_amount' => 20000,
            'label' => '$10.000 de descuento',
        ],
        'pts_2500_off_25k' => [
            'points' => 2500,
            'discount_type' => 'fixed_amount',
            'discount_value' => 25000,
            'min_order_amount' => 50000,
            'label' => '$25.000 de descuento',
        ],
    ],

    'redemption_expires_minutes' => (int) env('LOYALTY_REDEMPTION_EXPIRES_MINUTES', 30),

    // Refund reversa puntos otorgados por la orden (movement type=refund_reverse).
    'refund_reverses_points' => (bool) env('LOYALTY_REFUND_REVERSES_POINTS', true),

    // Job loyalty:expire-stale expira el balance de cuentas sin earn nuevo
    // en más de expire_after_months. null o 0 desactiva.
    'expire_after_months' => (int) env('LOYALTY_EXPIRE_AFTER_MONTHS', 12),

    // Ajustes manuales: límite por movimiento para evitar abuso de staff con
    // permiso loyalty.update. El total acumulado por audit_log queda visible.
    'max_manual_adjust_per_movement' => (int) env('LOYALTY_MAX_MANUAL_ADJUST', 10000),
];
