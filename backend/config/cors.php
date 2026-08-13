<?php

$frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');

/*
| Orígenes permitidos para CORS con credenciales.
|
| Fuente: `CORS_ALLOWED_ORIGINS` (lista separada por comas) si está definida.
| Si no, se deriva de `FRONTEND_URL` + los orígenes de desarrollo locales.
|
| El deploy típico es cross-origin same-site: frontend y API en subdominios
| distintos del mismo dominio. Para que la cookie HttpOnly del JWT
| (`bistro_jwt`) y la cookie de mesa (`tdt_*`) viajen entre ambos hay que:
|   1. `supports_credentials => true` (abajo).
|   2. Enumerar los orígenes exactos — `*` es inválido junto a credenciales.
|
| En dev el frontend usa el proxy de Vite (same-origin) y no dispara CORS,
| pero los orígenes locales se incluyen por si se levanta el SPA sin proxy.
*/
$allowedOrigins = array_values(array_unique(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', implode(',', array_filter([
        $frontendUrl,
        'http://localhost',
        'http://localhost:80',
        'http://localhost:5173',
    ]))))
))));

/*
| Los patrones de origen son configurables por env
| (`CORS_ALLOWED_ORIGINS_PATTERNS`, regexes separadas por `|||`). Sin valor,
| no hay wildcard de subdominios — usar la lista explícita de
| `CORS_ALLOWED_ORIGINS` de arriba. Solo activar un wildcard si tu deploy
| sirve carrito/menú desde subdominios dinámicos (ej. `pedidos.tu-dominio.com`)
| que necesiten CORS credenciado; hacerlo amplía la superficie de ataque
| (subdomain takeover) — usar con lista explícita cuando sea posible.
*/
$allowedOriginPatterns = array_values(array_filter(array_map(
    'trim',
    explode('|||', (string) env('CORS_ALLOWED_ORIGINS_PATTERNS', ''))
)));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | El SPA standalone (#220) consume la API cross-origin: necesita CORS con
    | credenciales para que las cookies HttpOnly viajen. `paths` cubre todo lo
    | que el SPA pega cross-origin: la API REST, las rutas públicas del QR de
    | mesa, el flujo OAuth de Google y el proxy de assets de S3.
    |
    | Restringido: nunca `allowed_origins=['*']`. El patrón de subdominios es
    | opt-in por env (vacío por default) y la lista explícita agrega los
    | orígenes locales de desarrollo.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'auth/*',
        'storage-proxy/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedOriginPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Cookie-Migrated'],

    'max_age' => 0,

    'supports_credentials' => true,

];
