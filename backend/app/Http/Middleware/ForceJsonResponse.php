<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza `Accept: application/json` en cualquier request al stack /api/*.
 *
 * Sin este middleware, un cliente que olvide el header Accept hace que el
 * ValidationException de Laravel devuelva 302 + redirect en lugar de 422 + JSON,
 * acabando en una respuesta HTML inapropiada para una API. El middleware no
 * cambia el cuerpo de la request — solo el header de negociación de contenido.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
