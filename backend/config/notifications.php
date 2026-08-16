<?php

/**
 * Configuración del sistema de notificaciones push.
 *
 * - `web_push`: claves VAPID (P-256) + subject (mailto). El subject lo usa el
 *   endpoint del navegador para contactar al operador del servicio en caso
 *   de abuso o entrega bloqueada.
 *
 * - `dispatch`: tuning del flujo de despacho.
 *   - `pending_approval_reminder_after_minutes`: minutos sin atender un item
 *     pendiente antes de empezar a mandar recordatorios.
 *   - `pending_approval_reminder_throttle_minutes`: distancia mínima entre
 *     recordatorios consecutivos para el mismo item (dedup vía cache lock).
 *   - `pending_approval_payload_tag_prefix`: prefijo del `tag` que colapsa
 *     duplicados a nivel OS.
 *
 * - `inventory_digest`: cómo se entrega el digest de inventario.
 *   - `enabled`: bandera global (kill-switch).
 *   - `daily_cache_ttl_minutes`: minutos hasta medianoche (cache marker
 *     `push.inventory.sent.{userId}.{date}`).
 */
return [
    'web_push' => [
        'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
        'vapid_subject' => env('VAPID_SUBJECT', 'mailto:hello@funcionbase.com'),
    ],

    'dispatch' => [
        'pending_approval_reminder_after_minutes' => 5,
        'pending_approval_reminder_throttle_minutes' => 5,
        'pending_approval_payload_tag_prefix' => 'pending-approval-',
    ],

    'inventory_digest' => [
        'enabled' => env('PUSH_INVENTORY_DIGEST_ENABLED', true),
        'tag_prefix' => 'inventory-digest-',
    ],
];
