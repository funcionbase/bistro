<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limit anti-abuso para las escrituras del bot externo (§8-whatsapp §9.6
 * controles #8 y #9). Dos cubetas sobre el store de cache (`database` en pdn →
 * tabla de Postgres, compartida entre instancias del ASG, §4.7):
 *
 *  - por conversación: 10/min. Es la defensa concreta contra el loop de n8n que
 *    quema el número — el envío masivo es la causa #1 de baneo (§13).
 *  - por empresa: 60/min. Techo agregado de todas las conversaciones del canal.
 *
 * La llave sale del `company_nit` del token de bot (nunca del body) + el
 * teléfono del cliente. Un 429 identifica la cubeta que lo gatilló y NO tumba a
 * las demás conversaciones ni a otras empresas: la ingesta legítima sigue.
 *
 * En el controller, no en un middleware `throttle:`, porque la llave depende de
 * `bot_company_nit` que inyecta `bot.jwt`, y el orden relativo de un
 * `ThrottleRequests` (prioritizado) vs. `bot.jwt` (no prioritizado) no está
 * garantizado. Mismo patrón que EvolutionWebhookController::originAllowed().
 */
trait ThrottlesBotChatWrites
{
    /**
     * @return JsonResponse|null 429 si se excede alguna cubeta; null si hay cupo.
     */
    protected function botWriteRateLimit(string $companyNit, string $conversationKey): ?JsonResponse
    {
        $buckets = [
            ['key' => "bot-chat:{$companyNit}:{$conversationKey}", 'max' => 10, 'scope' => 'chat'],
            ['key' => "bot-company:{$companyNit}", 'max' => 60, 'scope' => 'company'],
        ];

        foreach ($buckets as $bucket) {
            if (RateLimiter::tooManyAttempts($bucket['key'], $bucket['max'])) {
                return response()->json([
                    'message' => 'Demasiados mensajes seguidos; reintentá en unos segundos.',
                    'code' => 'rate_limited_'.$bucket['scope'],
                    'retry_after' => RateLimiter::availableIn($bucket['key']),
                ], 429);
            }
        }

        // Se cuenta contra ambas cubetas solo cuando ninguna estaba al tope: así
        // un chat que sí tiene cupo no consume el de la empresa si esta ya se pasó.
        foreach ($buckets as $bucket) {
            RateLimiter::hit($bucket['key'], 60);
        }

        return null;
    }
}
