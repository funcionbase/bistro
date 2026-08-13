<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Emite y verifica JWTs de sesión de carrito de compra con payload cifrado en AES-256-CBC.
 *
 * El header typ es "whatsapp_web_order" para distinguirlo del JWT de usuario (typ: JWT).
 * El campo jti (UUID) identifica unívocamente cada sesión de carrito en cart_sessions.
 * TTL configurable en config bot.cart_jwt_ttl (default: 4200s = 70 minutos).
 * La URL del carrito se construye como {cart_base_url}/{jwt}.
 *
 * @env CART_JWT_SECRET — clave HMAC-SHA256 para firma (config bot.cart_jwt_secret)
 * @env JWT_PAYLOAD_ENCRYPTION_KEY — clave AES-256 para cifrar el payload (config bot.cart_jwt_encryption_key)
 */
class CartJwtService
{
    private const ALGORITHM = 'HS256';

    private const TYPE = 'whatsapp_web_order';

    private const CIPHER = 'AES-256-CBC';

    private string $signingKey;

    private string $encryptionKey;

    private int $ttlSeconds;

    private string $baseUrl;

    public function __construct()
    {
        $this->signingKey = (string) config('bot.cart_jwt_secret');
        $this->encryptionKey = (string) config('bot.cart_jwt_encryption_key');
        $this->ttlSeconds = (int) config('bot.cart_jwt_ttl', 4200);
        $this->baseUrl = rtrim((string) config('bot.cart_base_url', 'http://localhost:5173'), '/');

        if (empty($this->signingKey) || empty($this->encryptionKey)) {
            throw new RuntimeException('CART_JWT_SECRET and JWT_PAYLOAD_ENCRYPTION_KEY must be configured.');
        }
    }

    /**
     * Generate a cart JWT with an encrypted payload.
     *
     * @param  array{session_id: string, client_phone: string, restaurant_phone: string, company_nit: string, menu_id?: string}  $cartData
     */
    public function generate(array $cartData): string
    {
        $jti = Str::uuid()->toString();
        $now = time();

        $payload = array_merge($cartData, [
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
        ]);

        $encryptedPayload = $this->encryptPayload($payload);

        $header = $this->base64UrlEncode(json_encode(['alg' => self::ALGORITHM, 'typ' => self::TYPE]));
        $body = $this->base64UrlEncode(json_encode(['data' => $encryptedPayload]));
        $signature = $this->sign("{$header}.{$body}");

        return "{$header}.{$body}.{$signature}";
    }

    /**
     * Generate the full cart URL containing the JWT.
     *
     * @param  array{session_id: string, client_phone: string, restaurant_phone: string, company_nit: string, menu_id?: string}  $cartData
     * @return array{jwt: string, url: string, jti: string, expires_at: int}
     */
    public function generateUrl(array $cartData): array
    {
        $jwt = $this->generate($cartData);
        $payload = $this->verify($jwt);

        return [
            'jwt' => $jwt,
            'url' => "{$this->baseUrl}/{$jwt}",
            'jti' => $payload['jti'],
            'expires_at' => $payload['exp'],
        ];
    }

    /**
     * Verify and decrypt a cart JWT, returning the payload.
     *
     * @return array{session_id: string, client_phone: string, restaurant_phone: string, company_nit: string, jti: string, iat: int, exp: int}
     *
     * @throws RuntimeException
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid cart JWT structure.');
        }

        [$header, $body, $signature] = $parts;

        $headerData = json_decode($this->base64UrlDecode($header), true);
        if (($headerData['typ'] ?? '') !== self::TYPE) {
            throw new RuntimeException('Invalid cart JWT type.');
        }

        $expectedSignature = $this->sign("{$header}.{$body}");
        if (! hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException('Cart JWT signature verification failed.');
        }

        $bodyDecoded = json_decode($this->base64UrlDecode($body), true);
        if (! isset($bodyDecoded['data'])) {
            throw new RuntimeException('Cart JWT body is malformed.');
        }

        $payload = $this->decryptPayload($bodyDecoded['data']);

        if (time() > $payload['exp']) {
            throw new RuntimeException('Cart JWT has expired.');
        }

        return $payload;
    }

    /** @param  array<string, mixed>  $payload */
    private function encryptPayload(array $payload): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $key = $this->deriveEncryptionKey();
        $encrypted = openssl_encrypt(json_encode($payload), self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return $this->base64UrlEncode($iv.$encrypted);
    }

    /** @return array<string, mixed> */
    private function decryptPayload(string $encryptedData): array
    {
        $raw = $this->base64UrlDecode($encryptedData);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);
        $key = $this->deriveEncryptionKey();

        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new RuntimeException('Cart JWT payload decryption failed.');
        }

        return json_decode($decrypted, true);
    }

    private function deriveEncryptionKey(): string
    {
        return hash('sha256', $this->encryptionKey, true);
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
