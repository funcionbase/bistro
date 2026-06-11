<?php

return [
    'notify_on_assignment' => env('DELIVERY_NOTIFY_CLIENT_ON_ASSIGNMENT', true),
    'notify_on_completion' => env('DELIVERY_NOTIFY_CLIENT_ON_COMPLETION', true),
    'share_courier_phone' => env('DELIVERY_SHARE_COURIER_PHONE', true),
    'max_active_per_courier' => (int) env('DELIVERY_MAX_ACTIVE_PER_COURIER', 3),
    'whatsapp_api_key' => env('WHATSAPP_API_KEY'),
    'whatsapp_phone_number' => env('WHATSAPP_PHONE_NUMBER'),
    'vehicle_type' => env('DELIVERY_VEHICLE_TYPE', 'bike'),
    'reassign_reasons' => [
        'client_request' => 'Cliente pidió cambio',
        'not_available' => 'Repartidor no disponible',
        'route_change' => 'Cambio de ruta',
        'other' => 'Otro (especificar)',
    ],
];
