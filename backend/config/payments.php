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
    // nequi/daviplata son aliases de 'transfer' — el backend los normaliza
    // a 'transfer' en payment_receipts para mantener la lista cerrada DIAN.
    'methods' => ['cash', 'card', 'transfer', 'nequi', 'daviplata'],

    // Set completo que puede aparecer en payment_receipts.payment_method (incluye refund).
    // Lista cerrada DIAN — nequi/daviplata se almacenan como 'transfer'.
    'receipt_methods' => ['cash', 'card', 'transfer', 'refund'],

    // Etiquetas en español para UI/PDFs/recibos.
    'labels' => [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'transfer' => 'Transferencia',
        'nequi' => 'Nequi',
        'daviplata' => 'Daviplata',
        'refund' => 'Devolución',
    ],

    // Métodos para los que `reference` es obligatoria (ver constants/PAYMENT_METHODS.md).
    // Efectivo se documenta con quién autorizó (actor_id del JWT en AuditLog).
    'requires_reference' => ['card', 'transfer', 'nequi', 'daviplata'],

    // Mapa slugs de empresa (company_settings.payment_methods, en español —
    // legado de /company/preferences) → slug canónico de `methods`. Conecta la
    // config de preferencias con el checkout público: el cliente ve solo los
    // métodos habilitados y su `orders.payment_preference` guarda el canónico.
    'company_aliases' => [
        'efectivo' => 'cash',
        'tarjeta' => 'card',
        'transferencia' => 'transfer',
        'nequi' => 'nequi',
        'daviplata' => 'daviplata',
    ],
];
