<?php

return [
    'jwt_secret' => env('BOT_JWT_SECRET'),
    'jwt_ttl' => (int) env('BOT_JWT_TTL', 3600),

    'cart_jwt_secret' => env('CART_JWT_SECRET'),
    'cart_jwt_encryption_key' => env('JWT_PAYLOAD_ENCRYPTION_KEY'),
    'cart_jwt_ttl' => (int) env('CART_JWT_TTL', 4200),
    'cart_base_url' => env('CART_BASE_URL', 'https://pedidos.flexyflow.co'),

    // TTL (horas) del link corto de carta enviado desde /chats
    // (/menus?cart={uuid}). Más largo que el CartJWT del bot: el cliente puede
    // abrir la carta horas después de recibirla.
    'menu_link_ttl_hours' => (int) env('MENU_LINK_TTL_HOURS', 24),

    'notify_on_status_change' => (bool) env('WHATSAPP_NOTIFY_ON_STATUS_CHANGE', false),
];
