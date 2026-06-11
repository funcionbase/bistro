<?php

declare(strict_types=1);

/**
 * Fuente única de verdad de métodos de pago. Cualquier código backend o
 * frontend (vía Inertia shared props) debe consumir esta config en lugar
 * de literales hard-coded.
 *
 * Distinción importante:
 *  - `methods`: opciones que se muestran al usuario para cobrar/seleccionar
 *    al momento de un pago (cash/card/transfer).
 *  - `receipt_methods`: lista cerrada de valores que pueden aparecer en
 *    `payment_receipts.payment_method`. Incluye `refund` que el sistema
 *    inserta cuando se hace una devolución (nunca seleccionable manualmente).
 *
 * Convención de signos (CLAUDE.md §13 + constants/PAYMENT_METHODS.md):
 *  - `cash | card | transfer` → `amount` positivo (cobro).
 *  - `refund` → `amount` negativo (devolución, asiento nuevo).
 */
return [

    // Métodos seleccionables al cobrar/registrar pago manual.
    'methods' => ['cash', 'card', 'transfer'],

    // Set completo que puede aparecer en payment_receipts.payment_method (incluye refund).
    'receipt_methods' => ['cash', 'card', 'transfer', 'refund'],

    // Etiquetas en español para UI/PDFs/recibos.
    'labels' => [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'transfer' => 'Transferencia',
        'refund' => 'Devolución',
    ],

    // Métodos para los que `reference` es obligatoria (#119 + constants/PAYMENT_METHODS.md).
    // Efectivo se documenta con quién autorizó (actor_id del JWT en AuditLog).
    'requires_reference' => ['card', 'transfer'],
];
