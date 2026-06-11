<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware que agrega `Cache-Control: private, max-age=N` a respuestas GET
 * exitosas, para reducir hits al backend desde el browser/CDN.
 *
 * - Solo aplica a GET/HEAD con status 200.
 * - `private` porque la mayoría del contenido depende del JWT del usuario.
 * - `max-age` configurable vía middleware alias: `cache.get:60` → 60 segundos.
 * - No aplica si la respuesta ya tiene `Cache-Control` (respeta lo que el
 *   controller declara explícitamente).
 *
 * Uso:
 *   Route::get('/catalogos/foo', ...)->middleware('cache.get:300');
 */
class CacheControlGet
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $maxAge = '60'): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        if ($response->headers->has('Cache-Control')) {
            return $response;
        }

        $response->headers->set('Cache-Control', sprintf('private, max-age=%d', max(0, (int) $maxAge)));

        return $response;
    }
}
