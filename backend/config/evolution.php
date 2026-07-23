<?php

declare(strict_types=1);

/**
 * Evolution API — proveedor de WhatsApp (plan 8-whatsapp.md §4.6, §6.2, §6.3).
 *
 * Evolution corre en el MISMO host que bistro, atado a 127.0.0.1 y sin puerto
 * abierto en el security group (§4.1-4.2). No hay servicio externo detrás de
 * esta config.
 */
return [
    // URL base del servidor por defecto. Cada canal puede apuntar a otro vía
    // `company_whatsapp_accounts.evo_server_url` (salida futura sin migración).
    'base_url' => env('EVOLUTION_BASE_URL', 'http://127.0.0.1:8080'),

    // Token GLOBAL (AUTHENTICATION_API_KEY de Evolution): solo para gestionar
    // instancias (create/connect/logout/delete/webhook). La mensajería usa el
    // token de la instancia, que se guarda cifrado por canal.
    'global_token' => env('EVOLUTION_GLOBAL_TOKEN'),

    'timeout' => (int) env('EVOLUTION_TIMEOUT', 15),

    // Prefijo del nombre de instancia: `bistro-{env}-{nit}-{sede|company}`.
    'instance_prefix' => env('EVOLUTION_INSTANCE_PREFIX', 'bistro'),

    'webhook' => [
        // URL pública que Evolution llama. Se completa con /{account} al
        // registrar el webhook de cada canal.
        'base_url' => env('EVOLUTION_WEBHOOK_BASE_URL', env('APP_URL').'/api/v1/webhooks/whatsapp/evolution'),

        // Header compartido. El valor viaja por canal (secreto de 32 bytes), el
        // nombre es fijo.
        'header' => 'X-Flexyflow-Token',

        // IPs autorizadas, separadas por coma. VACÍO = allowlist desactivada.
        // En pdn es la IP privada de la EC2; en local, la del bridge de Docker.
        // Nunca hardcodear: dejaría el desarrollo local sin webhook.
        'allowed_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('EVOLUTION_WEBHOOK_ALLOWED_IPS', ''))
        ))),

        // Peticiones por minuto y por canal.
        'rate_limit' => (int) env('EVOLUTION_WEBHOOK_RATE_LIMIT', 240),
    ],

    'media' => [
        // Tope propio, independiente de lo que acepte WhatsApp: el host es
        // compartido y el egreso de S3 se paga (§6.7).
        'max_bytes' => (int) env('EVOLUTION_MEDIA_MAX_BYTES', 16 * 1024 * 1024),

        // TTL de la URL prefirmada que Evolution consume para subir un adjunto.
        'presigned_ttl_minutes' => (int) env('EVOLUTION_MEDIA_PRESIGNED_TTL', 10),

        /*
         * Lista blanca por tipo. SVG y HTML quedan FUERA a propósito: se sirven
         * desde nuestro dominio y ejecutan script (XSS). Tampoco ejecutables.
         */
        'allowed_mimes' => [
            'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'video' => ['video/mp4', 'video/3gpp', 'video/quicktime'],
            'audio' => ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr'],
            'document' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'text/csv',
            ],
            'sticker' => ['image/webp'],
        ],
    ],

    'health' => [
        // Ciclos consecutivos caído antes de alertar. Con el poll cada 5 min,
        // 2 ciclos = ~10 min de caída sostenida: filtra el parpadeo de red.
        'failure_threshold' => (int) env('EVOLUTION_HEALTH_FAILURE_THRESHOLD', 2),

        // Horas que sobrevive un canal a medio conectar antes de purgarse
        // (§8.4b punto 5). Ocupa el slot del índice único parcial, así que sin
        // purga el que cerró el modal del QR no puede volver a conectar nunca.
        // 24 h deja margen para retomar al día siguiente sin dejar basura.
        'pending_ttl_hours' => (int) env('EVOLUTION_PENDING_TTL_HOURS', 24),
    ],
];
