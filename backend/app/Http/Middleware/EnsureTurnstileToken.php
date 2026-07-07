<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida el token de Cloudflare Turnstile antes de llegar al controller.
 *
 * Anti-spam para los endpoints de auth que crean cuenta o disparan correo
 * (registro, forgot-password) y para el login (credential-stuffing). El
 * token del widget viaja en el body `cf-turnstile-response` (o el header
 * `CF-Turnstile-Response`); se verifica server-to-server contra el
 * `siteverify` de Cloudflare con el secret del .env.
 *
 * Fail-open por diseño (misma filosofía que `Password::uncompromised`):
 *  - Sin `secret` configurado (local/qa) → pasa: no estorba el desarrollo.
 *  - `siteverify` inalcanzable / timeout (caída de Cloudflare) → pasa y loguea:
 *    no dejamos a usuarios legítimos afuera por un incidente de terceros; los
 *    rate limiters (lockout de login, throttle de registro/forgot) siguen de
 *    backstop.
 * Solo se rechaza (422) cuando Cloudflare responde explícitamente que el token
 * es inválido/ausente estando la protección activa.
 */
class EnsureTurnstileToken
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.turnstile.secret');

        // Protección desactivada (sin secret) → fail-open.
        if (! is_string($secret) || $secret === '') {
            return $next($request);
        }

        $token = $request->input('cf-turnstile-response')
            ?? $request->header('CF-Turnstile-Response');

        if (! is_string($token) || $token === '') {
            return $this->reject('Completa la verificación de seguridad e intenta de nuevo.');
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->retry(1, 200)
                ->post(self::SITEVERIFY_URL, [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (\Throwable $e) {
            // Cloudflare inalcanzable → fail-open (no bloquear por caída ajena).
            Log::warning('turnstile.verify_unreachable', ['error' => $e->getMessage()]);

            return $next($request);
        }

        if ($response->successful() && $response->json('success') === true) {
            return $next($request);
        }

        Log::info('turnstile.verify_failed', [
            'ip' => $request->ip(),
            'codes' => $response->json('error-codes'),
        ]);

        return $this->reject('La verificación de seguridad falló. Recarga la página e intenta de nuevo.');
    }

    private function reject(string $message): Response
    {
        return response()->json([
            'message' => $message,
            'errors' => ['captcha' => [$message]],
        ], 422);
    }
}
