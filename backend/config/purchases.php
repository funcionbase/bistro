<?php

declare(strict_types=1);

/**
 * Configuración canónica del módulo de compras a proveedores. Fuente única
 * de verdad para estados, transiciones y métodos de pago — consumir SIEMPRE
 * vía `config('purchases.*')`, nunca hardcodear listas en código.
 */
return [

    'statuses' => [
        'draft',
        'pending',
        'received',
        'paid',
        'cancelled',
        'voided',
    ],

    /*
     * Transiciones permitidas: status_actual => [statuses_destino_validos].
     * Cualquier transición fuera de este mapa la rechaza PurchaseService.
     *
     * Notas:
     *  - draft → cancelled: descarte sin impacto.
     *  - pending → cancelled: cancelación pre-recepción (sin afectar inventario).
     *  - received → voided: anulación post-recepción → exige nota crédito + reverso.
     *  - paid → voided: ídem, además levanta `pending_supplier_refund`.
     *  - paid no vuelve a received: el pago es terminal salvo anulación.
     */
    'transitions' => [
        'draft' => ['pending', 'cancelled'],
        'pending' => ['received', 'cancelled'],
        'received' => ['paid', 'voided'],
        'paid' => ['voided'],
        'cancelled' => [],
        'voided' => [],
    ],

    // Espejo de `config('payments.methods')` (#203). Para cambiar el catálogo,
    // editar `config/payments.php` y reflejarlo aquí en el mismo PR.
    'payment_methods' => ['cash', 'card', 'transfer'],

    'attachment_types' => ['invoice', 'delivery_note', 'payment_proof', 'other'],

    'attachment_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],

    'attachment_max_bytes' => 10 * 1024 * 1024, // 10 MB

    /*
     * Disco de almacenamiento para los adjuntos de orden de compra (facturas
     * de proveedor). Por defecto `local` (storage/app/private) para dev sin
     * docker. En prod se setea PURCHASE_ATTACHMENT_DISK=s3_documents para que
     * vayan al bucket privado (DIAN retención 10 años).
     */
    'attachment_disk' => env('PURCHASE_ATTACHMENT_DISK', 'local'),

    'code_prefix' => 'PO-',

    'credit_note_prefix' => 'NC-',
];
