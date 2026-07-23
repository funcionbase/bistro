<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AutomationFlow;
use App\Models\Company;
use App\Services\BotJwtService;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación del bot externo por TOKEN POR FLUJO (alias bot.token, §7.5.1).
 *
 * Reemplaza a `bot.jwt` cerrando el agujero cross-tenant del `BOT_JWT_SECRET`
 * global: el token pertenece a un flujo atado a (empresa, sede), es revocable
 * (rotar o `enabled=false`) y se guarda hasheado, no cifrado reversible.
 *
 * Ventana de convivencia: acepta AMBOS esquemas. El token nuevo (`ffw_…`) o el
 * JWT legado. Cada uso del legado deja un log `bot.auth.legacy_jwt`; cuando ese
 * log queda en cero se retira el soporte de JWT y `BotJwtService` (§11.1).
 *
 * Inyecta en request: bot_company_nit, bot_branch_id (null para flujo de
 * empresa o para el legado), bot_flow_id (null para el legado).
 *
 * #193: si la empresa está bloqueada por mora responde 503 `company_unavailable`
 * — el bot deja de procesar sin que el comensal sepa el motivo comercial.
 */
class ValidateBotToken
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->unauthorized();
        }

        $companyNit = null;
        $branchId = null;
        $flowId = null;

        if (str_starts_with($token, AutomationFlow::TOKEN_PREFIX)) {
            $flow = AutomationFlow::authenticate($token);
            if ($flow === null) {
                return $this->unauthorized();
            }
            $companyNit = $flow->company_nit;
            $branchId = $flow->branch_id;
            $flowId = $flow->id;
        } else {
            // Camino legado (JWT de bot). Se conserva SOLO durante la ventana de
            // convivencia; cada uso se registra para saber cuándo se puede retirar.
            $companyNit = $this->verifyLegacyJwt($token);
            if ($companyNit === null) {
                return $this->unauthorized();
            }
            Log::channel('single')->info('bot.auth.legacy_jwt', [
                'company_nit' => $companyNit,
                'path' => $request->path(),
            ]);
        }

        // Guard de empresa operativa (#193). 503 indistinguible de cualquier
        // indisponibilidad técnica; el bot consume el `code` para dejar de
        // procesar mensajes / canjes hasta que la empresa vuelva.
        $company = Company::query()->where('nit', $companyNit)->first();
        if ($company !== null && ! $company->canServePublic()) {
            return response()->json([
                'message' => 'El servicio de la empresa no está disponible en este momento.',
                'code' => 'company_unavailable',
            ], 503);
        }

        $request->attributes->set('bot_company_nit', $companyNit);
        $request->attributes->set('bot_branch_id', $branchId);
        $request->attributes->set('bot_flow_id', $flowId);

        return $next($request);
    }

    /** Verifica el JWT legado; devuelve el company_nit o null si es inválido. */
    private function verifyLegacyJwt(string $token): ?string
    {
        try {
            $service = Container::getInstance()->make(BotJwtService::class);
        } catch (RuntimeException $e) {
            Log::warning('[bot.token] BOT_JWT_SECRET no configurado; se rechaza el JWT legado.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            $payload = $service->verify($token);
        } catch (RuntimeException) {
            return null;
        }

        return $payload['company_nit'] ?? null;
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Token de bot inválido o expirado.'], 401);
    }
}
