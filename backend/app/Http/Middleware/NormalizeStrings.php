<?php

namespace App\Http\Middleware;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\NoControlCharacters;
use Closure;
use Illuminate\Http\Request;
use Normalizer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Normaliza strings del payload entrante:
 *
 * - Aplica Unicode NFC (Canonical Composition) para colapsar formas
 *   distintas que renderizan igual (e.g. `é` como U+00E9 vs
 *   U+0065 U+0301).
 * - No remueve control characters — eso lo decide cada FormRequest
 *   vía {@see NoControlCharacters} y la categoría declarada
 *   en el trait {@see SanitizesInput}.
 *
 * Whitelist de rutas excluidas (no se normaliza):
 * - `api/v1/webhooks/whatsapp` — el payload viene firmado por Meta;
 *   mutarlo invalidaría la signature.
 * - `api/v1/webhooks/ses-notifications` — payload firmado por AWS SNS;
 *   el `Signature` se valida contra el canonical string byte-exact del
 *   body original.
 * - `api/v1/csp-report` — reporte de violaciones CSP que viene del
 *   navegador, no de un usuario.
 *
 * Ver `docs/wiki/SECURITY_INPUT_HANDLING.md` para política completa.
 */
class NormalizeStrings
{
    /**
     * @var array<int, string>
     */
    protected array $except = [
        'api/v1/webhooks/whatsapp',
        'api/v1/webhooks/whatsapp/*',
        'api/v1/webhooks/ses-notifications',
        // Webhook DIAN (#235). Body firmado HMAC SHA-256 con
        // webhook_secret_encrypted de la empresa — normalizar Unicode
        // rompería la firma. Aplica a `mock` y a cualquier provider futuro.
        'api/v1/webhooks/dian/*',
        'api/v1/csp-report',
        'csp-report',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldNormalize($request)) {
            return $next($request);
        }

        if (! class_exists(Normalizer::class)) {
            return $next($request);
        }

        $input = $request->all();
        $normalized = $this->normalizeRecursive($input);

        if ($normalized !== $input) {
            $request->replace($normalized);
        }

        return $next($request);
    }

    protected function shouldNormalize(Request $request): bool
    {
        foreach ($this->except as $pattern) {
            if ($request->is($pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    protected function normalizeRecursive($value)
    {
        if (is_string($value)) {
            $result = Normalizer::normalize($value, Normalizer::FORM_C);

            return $result === false ? $value : $result;
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->normalizeRecursive($v), $value);
        }

        return $value;
    }
}
