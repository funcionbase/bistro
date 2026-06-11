<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        // En qa/pdn las credenciales vienen del IAM instance profile del ASG
        // (key/secret quedan vacios y el SDK los toma del IMDS). En local con
        // MinIO se inyectan via .env personal. Mismo patron que S3.
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

        // Configuration Set de SES (opcional). Habilita destinos SNS para
        // bounces/complaints, suppression list propia, dedicated IP pool, etc.
        // El driver `ses` de Laravel pasa este valor como header X-SES-CONFIGURATION-SET
        // en cada envio cuando esta presente. Vacio = sin configuration set.
        'options' => array_filter([
            'ConfigurationSetName' => env('SES_CONFIGURATION_SET'),
        ]),

        // Secreto compartido para validar el webhook `/api/v1/webhooks/ses-notifications`
        // (Fase 2). La firma SNS de AWS ya es criptograficamente segura, este
        // secreto es defensa en profundidad opcional pasado en query string al
        // configurar el SNS subscription (filter policy o custom HTTPS endpoint).
        'webhook_secret' => env('SES_WEBHOOK_SECRET'),
    ],

    'sns' => [
        // Envío de SMS al cliente por cambios de estado de orden (#275).
        // En qa/pdn las credenciales vienen del IAM instance profile del ASG
        // (key/secret vacíos → el SDK las toma del IMDS). Mismo patrón que SES/S3.
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),

        // Región del endpoint SNS. Por defecto reusa la región AWS global; se
        // puede sobreescribir con SNS_SMS_REGION si el SMS sale por otra región.
        // Usamos `?:` (no el default de env()) porque SNS_SMS_REGION suele venir
        // como string vacío en el .env — y env('x', def) NO aplica el default
        // cuando la var existe pero está vacía, solo cuando está ausente.
        'region' => env('SNS_SMS_REGION') ?: env('AWS_DEFAULT_REGION') ?: 'us-east-1',

        // Master switch del envío de SMS. Vacío/false en local y qa → el SMS no
        // se publica (se registra como skipped) para no gastar saldo SNS en
        // entornos no productivos. Se activa en pdn vía GH Env Vars.
        'sms_enabled' => filter_var(env('SNS_SMS_ENABLED', false), FILTER_VALIDATE_BOOL),

        // Transactional prioriza entrega sobre costo (#275, Decisión 2). No
        // cambiar a Promotional para notificaciones de orden.
        'sms_type' => env('SNS_SMS_TYPE', 'Transactional'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'ga4' => [
        // Measurement ID de Google Analytics 4 (formato `G-XXXXXXXXXX`).
        // Publico (no secreto): el frontend lo expone en gtag.js. Vacio en
        // local/qa => GA4 no carga; solo se setea en pdn (ver BootstrapService).
        'measurement_id' => env('GA4_MEASUREMENT_ID'),
    ],

];
