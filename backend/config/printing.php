<?php

declare(strict_types=1);

/**
 * Fuente única de verdad para impresión de comandas/recibos. Cualquier código
 * backend o frontend (vía Inertia shared props) debe consumir esta config en
 * lugar de literales hardcoded.
 */
return [

    'types' => [
        'kitchen' => 'Cocina',
        'bar' => 'Barra',
        'cashier' => 'Caja',
        'customer_receipt' => 'Recibo cliente',
    ],

    'connections' => [
        'usb' => 'USB',
        'bluetooth' => 'Bluetooth',
        'lan' => 'LAN (agente HTTP)',
    ],

    'paper_widths' => [58, 80],

    // Tipos cuyo destino son comandas de preparación (no recibo del cliente).
    'command_types' => ['kitchen', 'bar'],

    // Estado de orden que dispara la impresión automática de comanda.
    'trigger_status' => 'in_kitchen',

    // Eventos auditables — strings cerrados.
    'audit' => [
        'printed' => 'order.command_printed',
        'reprinted' => 'order.command_reprinted',
        'failed' => 'order.command_print_failed',
        'tested' => 'printer.tested',
    ],

    // Reintentos del job de impresión.
    'job' => [
        'tries' => 3,
        'backoff' => [10, 30, 90],
        'timeout' => 30,
    ],

    // Driver HTTP del agente local (PrintNode-style). Timeout en segundos.
    'http_agent' => [
        'timeout' => 5,
    ],
];
