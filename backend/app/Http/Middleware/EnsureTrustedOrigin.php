<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defensa CSRF para la API.
 *
 * La cookie del JWT viaja `SameSite=None` (deploy cross-origin SPA↔API), así que
 * el navegador la adjunta también en peticiones cross-site. Las rutas `api/*` no
 * montan `VerifyCsrfToken` (es del grupo `web`), de modo que sin este chequeo una
 * página atacante podría disparar acciones de estado con content-types "simples"
 * (form/multipart/text) que no gatillan preflight CORS.
 *
 * Regla: en métodos NO idempotentes, si la request trae la cookie del JWT
 * (credencial ambiente), exige `Origin` (o `Referer` como fallback) dentro del
 * mismo allowlist que CORS. Clientes Bearer y webhooks (sin cookie) no son
 * susceptibles a CSRF y pasan sin chequeo.
 */
class EnsureTrustedOrigin
{
    /** @var list<string> */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        // Solo aplica cuando hay credencial ambiente (cookie del JWT). Sin
        // cookie (Bearer / webhooks firmados) no hay riesgo CSRF.
        if (! $request->cookies->has(JwtService::COOKIE_NAME)) {
            return $next($request);
        }

        $origin = $this->resolveOrigin($request);

        if ($origin === null || ! $this->isAllowed($origin)) {
            return response()->json([
                'message' => 'Origen de la solicitud no permitido.',
                'code' => 'CSRF_ORIGIN_REJECTED',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Origen efectivo de la request: header `Origin`, o el origin derivado del
     * `Referer` como fallback (algunos navegadores omiten Origin en same-site).
     */
    private function resolveOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && $origin !== '' && $origin !== 'null') {
            return rtrim($origin, '/');
        }

        $referer = $request->headers->get('Referer');
        if (is_string($referer) && $referer !== '') {
            $parts = parse_url($referer);
            if (isset($parts['scheme'], $parts['host'])) {
                $port = isset($parts['port']) ? ':'.$parts['port'] : '';

                return "{$parts['scheme']}://{$parts['host']}{$port}";
            }
        }

        return null;
    }

    private function isAllowed(string $origin): bool
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('cors.allowed_origins', []);
        foreach ($allowed as $candidate) {
            if (rtrim((string) $candidate, '/') === $origin) {
                return true;
            }
        }

        /** @var list<string> $patterns */
        $patterns = (array) config('cors.allowed_origins_patterns', []);
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }
}
