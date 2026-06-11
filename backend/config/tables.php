<?php

declare(strict_types=1);

/**
 * Configuración del flujo de mesa con QR (#191).
 *
 * Toda decisión de tiempo o validación que afecte sesiones de mesa, comensales
 * y QR vive aquí. Los reportes y servicios consumen `config('tables.*')` en
 * lugar de literales hard-coded.
 */
return [

    // Tiempo máximo sin actividad de pago antes de marcar la sesión como `expired`.
    // El job `tables:purge-expired-sessions` corre cada 5 min y cierra sesiones
    // que excedan este umbral. Default 4 horas — cubre almuerzo + sobremesa.
    'session_expiration_hours' => env('TABLES_SESSION_EXPIRATION_HOURS', 4),

    // Tiempo de vida de la cookie firmada `device_token` que identifica al
    // comensal. Más corto que la expiración de sesión para forzar re-join si
    // un cliente vuelve al día siguiente.
    'device_token_ttl_hours' => env('TABLES_DEVICE_TOKEN_TTL_HOURS', 12),

    // Validación del teléfono del comensal (Colombia, móvil obligatorio).
    // Regex aplicado tras normalizar (strip espacios, guiones, paréntesis y
    // prefijo `+57` o `57` inicial). Acepta solo móviles que arrancan con 3.
    'guest_phone_regex' => '/^3\d{9}$/',

    // Estados terminales de sesión.
    'terminal_statuses' => [
        'closed',
        'expired',
    ],

    // Estados activos (la mesa luce `occupied` cuando hay una sesión en alguno).
    'active_statuses' => [
        'open',
        'locked',
    ],

    // Rate limit del endpoint público `/t/{qr_token}`. Throttle agresivo para
    // evitar enumeración de tokens o DoS sobre el menú público.
    'rate_limit' => [
        'per_ip_per_minute' => env('TABLES_RATE_LIMIT_PER_IP', 30),
        'per_qr_per_minute' => env('TABLES_RATE_LIMIT_PER_QR', 200),
    ],
];
