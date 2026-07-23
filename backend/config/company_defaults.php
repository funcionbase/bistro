<?php

return [
    'timezone' => [
        'value' => 'America/Bogota',
        'type' => 'string',
    ],
    'currency' => [
        'value' => 'COP',
        'type' => 'string',
    ],
    'currency_symbol' => [
        'value' => '$',
        'type' => 'string',
    ],
    'language' => [
        'value' => 'es',
        'type' => 'string',
    ],
    'order_auto_confirm' => [
        'value' => false,
        'type' => 'boolean',
    ],
    'order_notify_customer_email' => [
        'value' => true,
        'type' => 'boolean',
    ],
    'bot_welcome_message' => [
        'value' => '¡Bienvenido a {company_name}! ¿En qué te podemos ayudar?',
        'type' => 'string',
    ],
    'bot_away_message' => [
        'value' => 'Estamos fuera de horario. Pronto te atenderemos.',
        'type' => 'string',
    ],
    'delivery_area_km' => [
        'value' => 10,
        'type' => 'integer',
    ],
    'min_order_amount' => [
        'value' => 0,
        'type' => 'integer',
    ],
    'payment_methods' => [
        'value' => ['efectivo', 'transferencia'],
        'type' => 'json',
    ],
    'payment_method_accounts' => [
        'value' => [],
        'type' => 'json',
    ],
    'menu_primary_color' => [
        'value' => '#FF6B35',
        'type' => 'string',
    ],
    // Si esta en true, marcamos los mensajes entrantes como "leidos" en Meta
    // (doble chulito azul para el cliente). Default off por privacidad: el
    // operador decide explicitamente cuando avisar al cliente que ya leyo.
    'whatsapp_read_receipts' => [
        'value' => false,
        'type' => 'boolean',
    ],
    // Si esta en true, un mensaje entrante FUERA de horario dispara una
    // respuesta automatica (`bot_away_message`), una sola vez por cliente y
    // ventana (§8.4b punto 10). Default off: una empresa que nunca configuro
    // automatizacion no debe empezar a auto-responder sola. El texto vive en
    // `bot_away_message`; este switch decide si se manda.
    'whatsapp_away_reply_enabled' => [
        'value' => false,
        'type' => 'boolean',
    ],
    // Configuración de impresión térmica (ESC/POS) — recibos de venta.
    'printing.receipt_width' => [
        'value' => 58,
        'type' => 'integer',
    ],
    'printing.header_lines' => [
        'value' => [],
        'type' => 'json',
    ],
    'printing.footer_message' => [
        'value' => '¡Gracias por tu visita!',
        'type' => 'string',
    ],
    'printing.show_qr_menu' => [
        'value' => false,
        'type' => 'boolean',
    ],
    'printing.copies' => [
        'value' => 1,
        'type' => 'integer',
    ],
    // Margen mínimo aceptable para reportes de food cost. Valor entre 0 y 1
    // (0.30 = 30%). Si el margen del plato cae por debajo, el dashboard lo
    // marca con badge de advertencia. Se almacena como string para evitar
    // pérdida de precisión al castear floats.
    'food_cost_alert_threshold' => [
        'value' => '0.30',
        'type' => 'string',
    ],
    // Fidelización. Defaults consistentes con config/loyalty.php: programa
    // apagado por defecto y ratio 0.001 (1 punto por cada $1.000 COP).
    'loyalty.enabled' => [
        'value' => false,
        'type' => 'boolean',
    ],
    'loyalty.points_per_cop' => [
        'value' => '0.001',
        'type' => 'string',
    ],
    // Facturación electrónica DIAN (HU #235).
    // `manual` por default — la empresa NO emite documentos DIAN automáticos
    // al cerrar una orden hasta que owner habilite explícitamente en
    // Configuración → Facturación DIAN. Evita activación inadvertida.
    'dian.auto_emit_on_close' => [
        'value' => 'manual',
        'type' => 'string',
    ],
    'dian.print_on_close' => [
        'value' => 'ask',
        'type' => 'string',
    ],
    'dian.default_document_type' => [
        'value' => 'pos_equivalent',
        'type' => 'string',
    ],
    'dian.lookup_by_phone_enabled' => [
        'value' => true,
        'type' => 'boolean',
    ],
];
