<?php

namespace App\Http\Middleware;

use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica el JWT de usuario y propaga el contexto autenticado al request.
 *
 * Fuente del token (en orden): cookie HttpOnly `flexyflow_jwt`, header `Authorization: Bearer`,
 * session flash, query string. Ver JwtService::extractTokenFromRequest().
 * Inyecta en request: jwt_token, jwt_payload, jwt_user_id.
 * Auto-refresco: si quedan menos de 300s para expirar, emite token nuevo y lo devuelve
 *   - como cookie HttpOnly (clientes navegador, prioridad por seguridad)
 *   - como header X-Refresh-Token (clientes API/Bearer que necesitan rotar manualmente)
 * Retorna 401 si el token no está presente, es inválido o expiró.
 *
 * @env JWT_SECRET — clave de firma HS256
 * @env JWT_PAYLOAD_ENCRYPTION_KEY — clave AES-256 para cifrar el payload
 */
class ValidateJwt
{
    public function __construct(private readonly JwtService $jwtService) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->jwtService->extractTokenFromRequest($request);

        if ($token === null) {
            return response()->json(['message' => 'Token no proporcionado.'], 401);
        }

        try {
            $payload = $this->jwtService->verify($token);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Token inválido o expirado.'], 401);
        }

        $request->attributes->set('jwt_token', $token);
        $request->attributes->set('jwt_payload', $payload);
        $request->attributes->set('jwt_user_id', (string) $payload['sub']);

        $response = $next($request);

        $secondsUntilExpiry = ($payload['exp'] ?? 0) - time();
        if ($secondsUntilExpiry < 300) {
            $refreshedToken = $this->jwtService->reissue($payload);
            $request->attributes->set('jwt_refreshed_token', $refreshedToken);
            $response->headers->setCookie($this->jwtService->buildCookie($refreshedToken));
            $response->headers->set('X-Refresh-Token', $refreshedToken);
        } elseif (! $request->cookie(JwtService::COOKIE_NAME)) {
            // Migración: el token llegó válido por Bearer/query (cliente legacy o sesión
            // pre-cookie), pero la cookie HttpOnly aún no existe. La sembramos sin rotar
            // el token para que la próxima request use exclusivamente la cookie y el
            // frontend pueda olvidarse del Bearer en localStorage.
            $response->headers->setCookie($this->jwtService->buildCookie($token));
            $response->headers->set('X-Cookie-Migrated', '1');
        }

        return $response;
    }
}
