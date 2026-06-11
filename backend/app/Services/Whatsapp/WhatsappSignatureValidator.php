<?php

namespace App\Services\Whatsapp;

use App\Models\MetaPlatformCredential;

/**
 * Valida la firma HMAC SHA-256 que Meta envia en el header X-Hub-Signature-256.
 *
 * Formato del header: 'sha256=<hex_digest>'.
 *
 * El digest se calcula con HMAC-SHA256 sobre el RAW body usando el App Secret
 * de la app de flexyflow (no el token del cliente). Si la firma no coincide,
 * descartamos el evento.
 *
 * Ver: https://developers.facebook.com/docs/graph-api/webhooks/getting-started#validate
 */
class WhatsappSignatureValidator
{
    public function isValid(string $rawPayload, ?string $signatureHeader, ?MetaPlatformCredential $credential = null): bool
    {
        if (empty($signatureHeader)) {
            return false;
        }

        $credential ??= MetaPlatformCredential::activeForCurrentEnvironment();

        if ($credential === null) {
            return false;
        }

        $appSecret = $credential->appSecret();

        if (empty($appSecret) || str_starts_with($appSecret, 'PLACEHOLDER_')) {
            return false;
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $providedDigest = substr($signatureHeader, 7);
        $expectedDigest = hash_hmac('sha256', $rawPayload, $appSecret);

        return hash_equals($expectedDigest, $providedDigest);
    }
}
