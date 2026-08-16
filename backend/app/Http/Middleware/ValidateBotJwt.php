<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\BotJwtService;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica el JWT exclusivo de bots externos (alias bot.jwt).
 *
 * Lee: cabecera Authorization (Bearer token de bot).
 * Inyecta en request: bot_jwt_payload, bot_company_nit.
 * A diferencia del JWT de usuario, no incluye active_company ni permisos RBAC;
 * solo identifica la empresa del bot.
 * Retorna 401 si el token no está presente, es inválido o expiró.
 *
 * Si la empresa identificada por el JWT del bot está bloqueada por
 * mora (`fully_blocked`), retorna 503 `code='company_unavailable'`. El bot
 * debe interpretar como "no procesar mensajes / no canjear puntos" — el
 * comensal no debe enterarse del motivo comercial, así que el mensaje es
 * genérico ("servicio no disponible").
 *
 * @env BOT_JWT_SECRET — clave de firma HS256 del JWT de bot
 */
class ValidateBotJwt
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return response()->json(['message' => 'Token de bot no proporcionado.'], 401);
        }

        try {
            $service = Container::getInstance()->make(BotJwtService::class);
        } catch (RuntimeException $e) {
            Log::warning('[bot.jwt] BOT_JWT_SECRET no configurado; rechazando autenticación.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Token de bot inválido o expirado.'], 401);
        }

        try {
            $payload = $service->verify($token);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Token de bot inválido o expirado.'], 401);
        }

        $companyNit = $payload['company_nit'];

        // Guard de empresa operativa. 503 indistinguible de cualquier
        // indisponibilidad técnica; el bot consume el `code` para dejar de
        // procesar mensajes / canjes hasta que la empresa vuelva.
        $company = Company::query()->where('nit', $companyNit)->first();
        if ($company !== null && ! $company->canServePublic()) {
            return response()->json([
                'message' => 'El servicio de la empresa no está disponible en este momento.',
                'code' => 'company_unavailable',
            ], 503);
        }

        $request->attributes->set('bot_jwt_payload', $payload);
        $request->attributes->set('bot_company_nit', $companyNit);

        return $next($request);
    }
}
