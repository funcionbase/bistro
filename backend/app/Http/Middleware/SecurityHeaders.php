<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agrega cabeceras de seguridad HTTP a todas las respuestas.
 *
 * Headers inyectados (solo si app.security_headers_enabled=true):
 * - Content-Security-Policy (si app.csp_enabled): bloquea scripts sin nonce; reporta a app.csp_report_uri
 * - X-Content-Type-Options: nosniff — previene MIME sniffing
 * - X-Frame-Options: SAMEORIGIN — protege contra clickjacking
 * - Referrer-Policy: strict-origin-when-cross-origin — limita fuga de referrer en requests cross-origin
 * - Permissions-Policy: deshabilita camera/microphone/geolocation (#174 P3-3)
 * - Strict-Transport-Security: HSTS un año + subdomains (#174 P3-3, gateado por app.hsts_enabled)
 *
 * El nonce CSP se genera por request y se pasa a Vite vía Vite::useCspNonce().
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        Vite::useCspNonce($nonce);

        $response = $next($request);

        if (! config('app.security_headers_enabled', false)) {
            return $response;
        }

        if (config('app.csp_enabled', false)) {
            $reportUri = config('app.csp_report_uri', '/api/v1/csp-report');

            $frontendOrigin = rtrim((string) config('app.frontend_url'), '/');
            $connectSrc = trim("'self' {$frontendOrigin}");

            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https://cdn.flexyflow.com https://lh3.googleusercontent.com",
                "font-src 'self' https://fonts.bunny.net",
                "connect-src {$connectSrc}",
                "frame-ancestors 'none'",
                "report-uri {$reportUri}",
            ]);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=()'
        );

        // HSTS gateado: el ALB ya emite HSTS en algunos entornos. Activarlo
        // dos veces no es nocivo, pero permitir desactivarlo via config evita
        // sorpresas en local (http://localhost).
        if (config('app.hsts_enabled', false)) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
