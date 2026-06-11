<?php

declare(strict_types=1);

use App\Services\JwtService;
use Illuminate\Support\Facades\Cookie;

/*
 * Helpers globales para las route closures de routes/web.php.
 *
 * Antes vivían dentro de routes/web.php; al ejecutar `route:cache` Laravel
 * serializa las closures pero NO conserva las funciones top-level del file,
 * así que al servir una request desde rutas cacheadas las funciones quedan
 * undefined → 500. Mantenerlas aquí (autoloaded vía composer.json `files`)
 * garantiza que siempre estén disponibles, con o sin caché de rutas.
 */

if (! function_exists('resolveWebJwt')) {
    /**
     * Resuelve el JWT activo para una ruta web.
     *
     * El token se extrae siguiendo la prioridad de JwtService::extractTokenFromRequest()
     * (cookie HttpOnly > Authorization > session flash > query param), se verifica y se
     * reemite si está por expirar (<300s) — en ese caso se renueva la cookie HttpOnly via
     * Cookie::queue(). Se retorna un marker opaco `'__authenticated__'` (no el JWT real)
     * como prop Inertia para que el JS del frontend nunca tenga acceso al token.
     *
     * @return array{token: ?string, payload: ?array<string, mixed>}
     */
    function resolveWebJwt(): array
    {
        $jwtService = app(JwtService::class);
        $token = $jwtService->extractTokenFromRequest(request());

        if (! $token) {
            return ['token' => null, 'payload' => null];
        }

        try {
            $payload = $jwtService->verify($token);
            if (($payload['exp'] ?? 0) - time() <= 300) {
                $token = $jwtService->reissue($payload);
                Cookie::queue($jwtService->buildCookie($token));
            } elseif (! request()->cookie(JwtService::COOKIE_NAME)) {
                // Migración: token válido vino por Bearer/session/query pero no hay cookie.
                Cookie::queue($jwtService->buildCookie($token));
            }

            return ['token' => '__authenticated__', 'payload' => $payload];
        } catch (RuntimeException) {
            return ['token' => null, 'payload' => null];
        }
    }
}

if (! function_exists('resolveActiveCompany')) {
    /**
     * Resuelve la empresa activa a partir del payload del JWT.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    function resolveActiveCompany(?array $payload): ?array
    {
        if (! $payload) {
            return null;
        }

        $activeCompanyNit = $payload['active_company_nit'] ?? null;
        if (! $activeCompanyNit) {
            return null;
        }

        return collect($payload['companies'] ?? [])
            ->first(fn (array $c) => $c['nit'] === $activeCompanyNit);
    }
}
