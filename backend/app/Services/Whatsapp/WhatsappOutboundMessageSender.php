<?php

namespace App\Services\Whatsapp;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Envia mensajes salientes (operador → cliente).
 *
 * Durante F2-F4 conviven dos proveedores: el canal decide cual. Si tiene
 * instancia de Evolution va por ahi; si solo tiene `phone_number_id` de Meta,
 * por Graph. No hay interfaz ni factory — son dos ramas de un `if` y una de las
 * dos se borra en F4.
 *
 * Si el chat no tiene canal, deja el mensaje en `failed` con el motivo. Nunca
 * lanza excepcion sin manejar: el operador tiene que ver por que no salio.
 *
 * Operacion sincrona: el operador necesita feedback inmediato.
 */
class WhatsappOutboundMessageSender
{
    public function __construct(
        private readonly ?MetaGraphApiClient $graph,
    ) {}

    public static function forCurrentEnvironment(): self
    {
        return new self(MetaGraphApiClient::forCurrentEnvironment());
    }

    /**
     * Intenta entregar el mensaje. Actualiza el registro local con el id del
     * proveedor y el status. Devuelve true si el proveedor acepto.
     */
    public function deliver(Chat $chat, ChatMessage $message): bool
    {
        $account = $this->resolveChannel($chat);

        if ($account === null) {
            // `chats.whatsapp_account_id` es nullable (§5.3): un chat creado por
            // el bot en una empresa sin canal conectado no tiene por donde salir.
            $this->markFailed($message, 'no_channel_for_chat');

            return false;
        }

        $toPhone = (string) $chat->client_phone;

        if (ltrim($toPhone, '+') === '') {
            $this->markFailed($message, 'invalid_recipient');

            return false;
        }

        if ($account->canSendViaEvolution()) {
            return $this->deliverViaEvolution($account, $chat, $message, $toPhone);
        }

        return $this->deliverViaMeta($account, $chat, $message, $toPhone);
    }

    /**
     * Cascada de resolucion del canal de salida (§6.1). Cuando resuelve por
     * fallback, lo PERSISTE en el chat para que la proxima vez sea directo.
     */
    public function resolveChannel(Chat $chat): ?CompanyWhatsappAccount
    {
        $account = $chat->resolveWhatsappChannel();

        if ($account !== null && $chat->whatsapp_account_id === null) {
            $chat->forceFill(['whatsapp_account_id' => $account->id])->save();
        }

        return $account;
    }

    private function deliverViaEvolution(
        CompanyWhatsappAccount $account,
        Chat $chat,
        ChatMessage $message,
        string $toPhone,
    ): bool {
        $client = EvolutionClient::forAccount($account);
        $instance = (string) $account->evo_instance;
        $token = (string) $account->evoToken();
        $payload = (array) ($message->media_payload ?? []);

        $result = match ($message->media_type) {
            null, '' => $client->sendText($instance, $token, $toPhone, (string) $message->body),

            'location' => $client->sendLocation(
                $instance,
                $token,
                $toPhone,
                (float) ($payload['lat'] ?? 0),
                (float) ($payload['lng'] ?? 0),
                // `name` y `address` son REQUERIDOS por sendLocation, pero la
                // ubicacion entrante llega sin ninguno de los dos (F0). Sin esta
                // sintesis, reenviar una ubicacion recibida da 400.
                (string) ($payload['name'] ?? 'Ubicación'),
                (string) ($payload['address'] ?? sprintf(
                    '%.6F, %.6F',
                    (float) ($payload['lat'] ?? 0),
                    (float) ($payload['lng'] ?? 0),
                )),
            ),

            'contact' => $client->sendContact($instance, $token, $toPhone, $this->contactsFor($payload)),

            default => $this->deliverMediaViaEvolution($client, $account, $message, $toPhone, $payload),
        };

        if (! ($result['ok'] ?? false)) {
            Log::channel('single')->warning('whatsapp.outbound.failed', [
                'chat_id' => $chat->id,
                'message_id' => $message->id,
                'provider' => 'evolution',
                'http_status' => $result['status'] ?? null,
            ]);

            $this->markFailed($message, $this->failureReason($result));

            return false;
        }

        $providerId = $result['key']['id'] ?? null;

        $message->forceFill([
            'meta_message_id' => is_string($providerId) ? $providerId : null,
            'status' => 'sent',
        ])->save();

        $this->logEvent($account, $chat, $message, $toPhone, $providerId);

        return true;
    }

    /**
     * Media saliente: se manda la URL PREFIRMADA de S3, no el base64. Empujar
     * 16 MB codificados por PHP-FPM en una instancia de 2 GB es justo lo que
     * §6.7 evita.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function deliverMediaViaEvolution(
        EvolutionClient $client,
        CompanyWhatsappAccount $account,
        ChatMessage $message,
        string $toPhone,
        array $payload,
    ): array {
        if (empty($message->media_path)) {
            return ['ok' => false, 'status' => 0, 'error' => 'media_path_missing'];
        }

        $url = Storage::disk((string) config('filesystems.default'))->temporaryUrl(
            (string) $message->media_path,
            now()->addMinutes((int) config('evolution.media.presigned_ttl_minutes', 10)),
        );

        $instance = (string) $account->evo_instance;
        $token = (string) $account->evoToken();

        // La nota de voz tiene endpoint propio: sendMedia con mediatype=audio
        // manda un ARCHIVO de audio, que se renderiza distinto en WhatsApp.
        if ($message->media_type === 'audio' && ! empty($payload['ptt'])) {
            return $client->sendWhatsAppAudio($instance, $token, $toPhone, $url);
        }

        // `sticker` NO esta en el enum de mediatype (image|document|video|audio):
        // tiene su propio endpoint.
        if ($message->media_type === 'sticker') {
            return $client->sendSticker($instance, $token, $toPhone, $url);
        }

        return $client->sendMedia(
            $instance,
            $token,
            $toPhone,
            (string) $message->media_type,
            $url,
            $payload['file_name'] ?? null,
            $payload['caption'] ?? null,
        );
    }

    /**
     * Evolution devuelve **400 para dos cosas distintas**: un body mal armado y
     * "ese número no tiene WhatsApp". El segundo caso llega como
     * `[{jid, exists: false, number}]` y es la causa de fallo mas comun en
     * produccion — un digito de mas en el telefono del cliente. Colapsarlo en
     * `evolution_api_error` deja al operador sin saber que corregir.
     *
     * @param  array<string, mixed>  $result
     */
    private function failureReason(array $result): string
    {
        $message = $result['response']['message'] ?? null;

        if (is_array($message)) {
            foreach ($message as $entry) {
                if (is_array($entry) && array_key_exists('exists', $entry) && $entry['exists'] === false) {
                    return 'recipient_not_on_whatsapp';
                }
            }
        }

        return 'evolution_api_error';
    }

    /**
     * `sendContact` exige `fullName` y `phoneNumber` por contacto (camelCase).
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{fullName: string, phoneNumber: string}>
     */
    private function contactsFor(array $payload): array
    {
        $contacts = [];

        foreach ((array) ($payload['contacts'] ?? []) as $contact) {
            $phones = (array) ($contact['phones'] ?? []);

            if ($phones === []) {
                continue;
            }

            $contacts[] = [
                'fullName' => (string) ($contact['name'] ?? 'Contacto'),
                'phoneNumber' => (string) $phones[0],
            ];
        }

        return $contacts;
    }

    private function deliverViaMeta(
        CompanyWhatsappAccount $account,
        Chat $chat,
        ChatMessage $message,
        string $toPhone,
    ): bool {
        if ($this->graph === null) {
            $this->markFailed($message, 'meta_credentials_missing');

            return false;
        }

        if (! $account->isConnected() || empty($account->phone_number_id)) {
            $this->markFailed($message, 'whatsapp_account_not_connected');

            return false;
        }

        $token = $account->accessToken();

        if (empty($token)) {
            $this->markFailed($message, 'whatsapp_access_token_missing');

            return false;
        }

        // Meta solo soporta texto en este camino; la media saliente llegó con
        // Evolution y el camino de Meta se retira en F4.
        if (! empty($message->media_type)) {
            $this->markFailed($message, 'media_unsupported_on_meta_channel');

            return false;
        }

        $result = $this->graph->sendTextMessage(
            (string) $account->phone_number_id,
            $token,
            EvolutionClient::toMsisdn($toPhone),
            (string) $message->body,
        );

        if (! $result['ok']) {
            Log::channel('single')->warning('whatsapp.outbound.failed', [
                'chat_id' => $chat->id,
                'message_id' => $message->id,
                'provider' => 'meta',
                'http_status' => $result['status'],
                'meta_error' => $result['body']['error'] ?? null,
            ]);

            $this->markFailed($message, 'graph_api_error');

            return false;
        }

        $message->forceFill([
            'meta_message_id' => $result['wamid'],
            'status' => 'sent',
        ])->save();

        $this->logEvent($account, $chat, $message, $toPhone, $result['wamid']);

        return true;
    }

    private function logEvent(
        CompanyWhatsappAccount $account,
        Chat $chat,
        ChatMessage $message,
        string $toPhone,
        ?string $providerMessageId,
    ): void {
        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $account->id,
            'event_type' => 'message_sent',
            'payload' => [
                'wa_id' => EvolutionClient::toMsisdn($toPhone),
                'meta_message_id' => $providerMessageId,
                'chat_id' => $chat->id,
                'chat_message_id' => $message->id,
            ],
            'created_at' => now(),
        ]);
    }

    private function markFailed(ChatMessage $message, string $reason): void
    {
        // El motivo se persiste, no solo se loguea: es lo que el operador lee en
        // el tooltip de la burbuja fallida (§8.4b punto 4). Sin esto el reintento
        // es a ciegas — un teléfono mal escrito se reintenta para siempre.
        $message->forceFill(['status' => 'failed', 'failure_reason' => $reason])->save();

        Log::channel('single')->warning('whatsapp.outbound.skipped', [
            'message_id' => $message->id,
            'reason' => $reason,
        ]);
    }
}
