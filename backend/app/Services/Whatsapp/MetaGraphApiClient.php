<?php

namespace App\Services\Whatsapp;

use App\Models\MetaPlatformCredential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP fino para WhatsApp Cloud API y Graph API. Cada metodo es un
 * wrapper de un endpoint de Meta — nada de logica de negocio aqui.
 *
 * Construye URLs con la version del Graph configurada en MetaPlatformCredential
 * (graph_api_version). Asi cuando se rote la version solo cambia la fila en BD.
 */
class MetaGraphApiClient
{
    public function __construct(
        private readonly MetaPlatformCredential $credential,
    ) {}

    public static function forCurrentEnvironment(): ?self
    {
        $credential = MetaPlatformCredential::activeForCurrentEnvironment();

        return $credential !== null ? new self($credential) : null;
    }

    /**
     * Intercambia el `code` recibido en Embedded Signup por un access token
     * permanente del cliente sobre su WABA.
     *
     * Endpoint: GET /{graph-api-version}/oauth/access_token
     */
    public function exchangeCodeForAccessToken(string $code): array
    {
        $response = $this->client()->get($this->endpoint('oauth/access_token'), [
            'client_id' => $this->credential->app_id,
            'client_secret' => $this->credential->appSecret(),
            'code' => $code,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Suscribe la app de flexyflow al WABA del cliente para recibir webhooks
     * de mensajes y status updates.
     *
     * Endpoint: POST /{waba-id}/subscribed_apps
     */
    public function subscribeAppToWaba(string $wabaId, string $accessToken): array
    {
        $response = $this->clientWithToken($accessToken)
            ->post($this->endpoint("{$wabaId}/subscribed_apps"));

        return $response->json() ?? [];
    }

    /**
     * Trae metadata actualizada de un Phone Number (display name, quality,
     * tier de mensajeria, etc.) para sincronizar la fila local.
     *
     * Endpoint: GET /{phone-number-id}
     */
    public function getPhoneNumber(string $phoneNumberId, string $accessToken): array
    {
        $response = $this->clientWithToken($accessToken)
            ->get($this->endpoint($phoneNumberId), [
                'fields' => 'verified_name,display_phone_number,quality_rating,messaging_limit_tier,name_status',
            ]);

        return $response->json() ?? [];
    }

    /**
     * Libera el numero del cliente en la WABA. Lo hacemos cuando el cliente
     * pide cambiar de numero o desconectar la cuenta. La WABA sobrevive y
     * permite registrar un numero nuevo via Embedded Signup re-lanzado.
     *
     * Endpoint: DELETE /{phone-number-id}
     */
    public function deletePhoneNumber(string $phoneNumberId, string $accessToken): array
    {
        $response = $this->clientWithToken($accessToken)
            ->delete($this->endpoint($phoneNumberId));

        return $response->json() ?? ['success' => $response->successful()];
    }

    /**
     * Resuelve la URL temporal de descarga de un media (sticker, image, etc.)
     * a partir de su id en Meta. La URL solo vive ~5 minutos, asi que se usa
     * y se descarta — no se guarda en BD.
     *
     * Endpoint: GET /{media-id}
     *
     * @return array{url: ?string, mime_type: ?string, file_size: ?int, sha256: ?string}
     */
    public function getMediaMetadata(string $mediaId, string $accessToken): array
    {
        $response = $this->clientWithToken($accessToken)
            ->get($this->endpoint($mediaId));

        $payload = $response->json() ?? [];

        return [
            'url' => $payload['url'] ?? null,
            'mime_type' => $payload['mime_type'] ?? null,
            'file_size' => isset($payload['file_size']) ? (int) $payload['file_size'] : null,
            'sha256' => $payload['sha256'] ?? null,
        ];
    }

    /**
     * Descarga el contenido binario de un media usando la URL temporal devuelta
     * por getMediaMetadata. Se firma con el system_user_token / access_token del
     * cliente — Meta valida la autorizacion en este endpoint tambien.
     */
    public function downloadMediaBinary(string $url, string $accessToken): ?string
    {
        $response = $this->clientWithToken($accessToken)->get($url);

        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }

    /**
     * Marca un mensaje entrante como "leido" — produce el doble chulito azul
     * en el WhatsApp del cliente. Solo se llama si la empresa habilito el
     * setting `whatsapp_read_receipts`.
     *
     * Endpoint: POST /{phone-number-id}/messages con status=read.
     */
    public function markMessageAsRead(string $phoneNumberId, string $accessToken, string $metaMessageId): bool
    {
        $response = $this->clientWithToken($accessToken)
            ->post($this->endpoint("{$phoneNumberId}/messages"), [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $metaMessageId,
            ]);

        return $response->successful();
    }

    /**
     * Envia un mensaje de texto saliente desde el numero del cliente al wa_id
     * destino. Usado por el operador cuando responde manualmente desde el panel.
     *
     * Endpoint: POST /{phone-number-id}/messages
     *
     * @return array{ok: bool, status: int, wamid: ?string, body: array}
     */
    public function sendTextMessage(string $phoneNumberId, string $accessToken, string $toWaId, string $body): array
    {
        $response = $this->clientWithToken($accessToken)
            ->post($this->endpoint("{$phoneNumberId}/messages"), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $toWaId,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $body,
                ],
            ]);

        $payload = $response->json() ?? [];

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'wamid' => $payload['messages'][0]['id'] ?? null,
            'body' => $payload,
        ];
    }

    private function client(): PendingRequest
    {
        // No usamos ->throw(): preferimos inspeccionar $response->successful() y
        // surfaceear el error de Meta intacto al log/UI en vez de explotar.
        return Http::acceptJson();
    }

    private function clientWithToken(string $token): PendingRequest
    {
        return $this->client()->withToken($token);
    }

    private function endpoint(string $path): string
    {
        $version = $this->credential->graph_api_version;

        return "https://graph.facebook.com/{$version}/{$path}";
    }
}
