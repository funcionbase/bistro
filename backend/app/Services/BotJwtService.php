<?php

namespace App\Services;

use RuntimeException;

/**
 * Issues and verifies plain (non-encrypted) HS256 JWTs for chatbot authentication.
 * Uses BOT_JWT_SECRET — separate from the user JWT secret.
 */
class BotJwtService
{
    private string $signingKey;

    private int $ttlSeconds;

    public function __construct()
    {
        $this->signingKey = (string) config('bot.jwt_secret');
        $this->ttlSeconds = (int) config('bot.jwt_ttl', 3600);

        if (empty($this->signingKey)) {
            throw new RuntimeException('BOT_JWT_SECRET must be configured.');
        }
    }

    /**
     * Issue a bot JWT scoped to a specific company.
     */
    public function issue(string $companyNit): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'company_nit' => $companyNit,
            'iat' => time(),
            'exp' => time() + $this->ttlSeconds,
        ]));
        $signature = $this->sign("{$header}.{$payload}");

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Verify a bot JWT and return its payload.
     *
     * @return array{company_nit: string, iat: int, exp: int}
     *
     * @throws RuntimeException
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid bot JWT structure.');
        }

        [$header, $body, $signature] = $parts;

        $expectedSignature = $this->sign("{$header}.{$body}");
        if (! hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException('Bot JWT signature verification failed.');
        }

        $payload = json_decode($this->base64UrlDecode($body), true);

        if (! isset($payload['company_nit'], $payload['exp'])) {
            throw new RuntimeException('Bot JWT payload is malformed.');
        }

        if (time() > $payload['exp']) {
            throw new RuntimeException('Bot JWT has expired.');
        }

        return $payload;
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $data, $this->signingKey, true));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
