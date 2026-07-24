<?php

declare(strict_types=1);

/**
 * Configuración de notificaciones al cliente por cambios de estado de la orden
 * (#275 — SMS vía Amazon SNS).
 *
 * Fuente única de los estados que disparan SMS y del texto orientado al
 * cliente. NUNCA hardcodear estas listas en el controller/job — consumir esta
 * config. Los slugs de estado son espejo de `config/orders.php`.
 *
 * Decisión de contenido (#275, Decisión 2): el remitente en Colombia no puede
 * ser un Sender ID con marca, así que el nombre comercial va SIEMPRE en el
 * cuerpo. El texto es ASCII puro (sin tildes) a propósito: mantiene el SMS en
 * 1 segmento GSM-7 (160 chars) y controla el costo por segmento.
 */
return [

    // Estados de `orders.status` que disparan un SMS al cliente. Solo estados
    // relevantes para el cliente (#275, Regla 2). NO incluye estados internos
    // (pending_approval, pending, abandoned) ni terminales negativos
    // (cancelled, refunded, failed) — esos quedan fuera de alcance.
    'sms_statuses' => [
        'in_kitchen',
        'ready',
        'in_transit',
        'completed',
    ],

    // Frase orientada al cliente por estado (se interpola en la plantilla).
    // ASCII puro a propósito (ver nota de cabecera). Distinta de
    // `config('orders.labels.*')`, que es la etiqueta operativa interna.
    'sms_phrases' => [
        'in_kitchen' => 'ya esta EN PREPARACION',
        'ready' => 'ya esta LISTO',
        'in_transit' => 'va EN CAMINO',
        'completed' => 'fue ENTREGADO. Gracias!',
    ],

    // Plantilla del cuerpo. Placeholders: :brand, :code, :phrase.
    // Ejemplo: "Flexy Burger: tu pedido #A3F9C1 va EN CAMINO."
    'sms_template' => ':brand: tu pedido #:code :phrase',

    // Tope de caracteres del nombre comercial dentro del SMS, para no inflar el
    // mensaje a >1 segmento. Si el nombre es más largo se trunca con '…' → '...'.
    'brand_max_chars' => 24,

    // WhatsApp primario / SMS de respaldo. Cuando está activo, la notificación
    // se intenta primero por WhatsApp si el cliente escribió por ese canal
    // dentro de la ventana (abajo) y hay canal Evolution conectado; si no está
    // disponible o el envío falla, cae al SMS. Kill-switch de PDN: apagar por
    // env revierte a SMS-only sin deploy.
    'whatsapp_primary' => (bool) env('ORDER_NOTIFICATIONS_WHATSAPP_PRIMARY', true),

    // Ventana (horas) desde el último mensaje ENTRANTE del cliente por WhatsApp
    // dentro de la cual se considera "conversación abierta por él" y es seguro
    // notificar por ahí. Fuera de la ventana se usa SMS: enviar WhatsApp
    // proactivo a un número que no escribió recientemente arriesga baneo de la
    // sesión de Baileys (no hay plantillas aprobadas como en Meta Cloud API).
    'whatsapp_window_hours' => (int) env('ORDER_NOTIFICATIONS_WHATSAPP_WINDOW_HOURS', 24),
];
