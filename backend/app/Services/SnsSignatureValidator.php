<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Validador de firmas SNS (AWS Simple Notification Service).
 *
 * Cada mensaje SNS llega con `Signature`, `SigningCertURL` y `SignatureVersion`
 * en el JSON. La validación garantiza que el payload viene de AWS y no fue
 * manipulado:
 *
 *  1. Descargar el certificado X.509 desde `SigningCertURL` (debe ser un
 *     dominio amazonaws.com — anti-SSRF crítico).
 *  2. Reconstruir el canonical string del mensaje según las reglas de AWS
 *     (campos específicos en orden, terminados con \n).
 *  3. Verificar con la pública del cert + SHA1 (v1) o SHA256 (v2) sobre
 *     la firma base64 del campo `Signature`.
 *
 * Referencia oficial:
 * https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html
 *
 * Cache: el certificado de SNS rota cada cierto tiempo. Lo cacheamos por URL
 * 24h — si AWS rota antes, el siguiente request fallará la verificación una
 * vez (se reintenta sin cache).
 */
class SnsSignatureValidator
{
    /** Dominios donde AWS publica los certs SNS. Anti-SSRF crítico. */
    private const ALLOWED_CERT_HOSTS = [
        'sns.us-east-1.amazonaws.com',
        'sns.us-east-2.amazonaws.com',
        'sns.us-west-1.amazonaws.com',
        'sns.us-west-2.amazonaws.com',
        'sns.eu-west-1.amazonaws.com',
        'sns.sa-east-1.amazonaws.com',
    ];

    /**
     * @param  array<string, mixed>  $payload  Body JSON decodificado del POST de SNS.
     */
    public function isValid(array $payload): bool
    {
        $required = ['Type', 'MessageId', 'TopicArn', 'Timestamp', 'Signature', 'SigningCertURL'];
        foreach ($required as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                Log::channel('single')->warning('sns.signature.missing_field', ['field' => $field]);

                return false;
            }
        }

        if (! $this->isAllowedCertUrl($payload['SigningCertURL'])) {
            Log::channel('single')->warning('sns.signature.invalid_cert_url', [
                'url' => $payload['SigningCertURL'],
            ]);

            return false;
        }

        $cert = $this->fetchCertificate($payload['SigningCertURL']);
        if ($cert === null) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($cert);
        if ($publicKey === false) {
            Log::channel('single')->warning('sns.signature.cert_parse_failed');

            return false;
        }

        $canonical = $this->buildCanonicalString($payload);
        $signature = base64_decode($payload['Signature'], true);
        if ($signature === false) {
            return false;
        }

        $algo = ($payload['SignatureVersion'] ?? '1') === '2'
            ? OPENSSL_ALGO_SHA256
            : OPENSSL_ALGO_SHA1;

        $result = openssl_verify($canonical, $signature, $publicKey, $algo);

        return $result === 1;
    }

    private function isAllowedCertUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || ($parsed['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = $parsed['host'] ?? '';

        // Aceptamos sns.<region>.amazonaws.com (cualquier region) — el sufijo
        // amazonaws.com más prefijo sns.* es suficiente para descartar URLs
        // fabricadas. Lista exacta arriba como defensa extra.
        if (in_array($host, self::ALLOWED_CERT_HOSTS, true)) {
            return true;
        }

        // Fallback regex para regiones nuevas que no estén en la lista.
        return (bool) preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host);
    }

    private function fetchCertificate(string $url): ?string
    {
        $cacheKey = 'sns:cert:'.md5($url);

        return cache()->remember($cacheKey, now()->addHours(24), function () use ($url) {
            try {
                $response = Http::timeout(5)->get($url);

                if (! $response->successful()) {
                    Log::channel('single')->warning('sns.signature.cert_fetch_failed', [
                        'url' => $url,
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                return $response->body();
            } catch (\Throwable $e) {
                Log::channel('single')->error('sns.signature.cert_fetch_exception', [
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Reconstruye el string canónico que AWS firmó. Los campos y orden están
     * fijados por el spec — cambiarlos rompe la verificación.
     *
     * Notification:
     *   Message\nMessageId\nSubject\n[Subject value]\nTimestamp\n[ts]\nTopicArn\n[arn]\nType\n[type]\n
     *
     * SubscriptionConfirmation / UnsubscribeConfirmation:
     *   Message\n[msg]\nMessageId\n[id]\nSubscribeURL\n[url]\nTimestamp\n[ts]\nToken\n[tok]\nTopicArn\n[arn]\nType\n[type]\n
     *
     * @param  array<string, mixed>  $payload
     */
    private function buildCanonicalString(array $payload): string
    {
        $type = $payload['Type'] ?? '';

        $fields = match ($type) {
            'Notification' => array_filter([
                'Message' => $payload['Message'] ?? '',
                'MessageId' => $payload['MessageId'] ?? '',
                'Subject' => $payload['Subject'] ?? null, // sólo si presente
                'Timestamp' => $payload['Timestamp'] ?? '',
                'TopicArn' => $payload['TopicArn'] ?? '',
                'Type' => $type,
            ], fn ($v) => $v !== null),

            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => [
                'Message' => $payload['Message'] ?? '',
                'MessageId' => $payload['MessageId'] ?? '',
                'SubscribeURL' => $payload['SubscribeURL'] ?? '',
                'Timestamp' => $payload['Timestamp'] ?? '',
                'Token' => $payload['Token'] ?? '',
                'TopicArn' => $payload['TopicArn'] ?? '',
                'Type' => $type,
            ],

            default => [],
        };

        $out = '';
        foreach ($fields as $key => $value) {
            $out .= $key."\n".$value."\n";
        }

        return $out;
    }
}
