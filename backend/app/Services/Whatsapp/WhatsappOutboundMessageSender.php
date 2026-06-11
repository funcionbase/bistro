<?php

namespace App\Services\Whatsapp;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensajes salientes (operador → cliente) a la WhatsApp Cloud API.
 *
 * Resuelve la CompanyWhatsappAccount conectada de la empresa, usa su
 * phone_number_id y access_token para postear a Graph y persiste el
 * meta_message_id (wamid) y el estado (sent/failed) sobre el ChatMessage.
 *
 * Si la empresa no tiene cuenta conectada o falta token, deja el mensaje
 * con status='failed' y registra el motivo. Operacion sincrona por ahora —
 * el operador necesita feedback inmediato. Se puede mover a queue si Meta
 * empieza a tardar.
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
     * Intenta entregar el mensaje al destinatario via Graph. Actualiza el
     * registro local con el wamid y status. Devuelve true si Meta acepto.
     */
    public function deliver(Chat $chat, ChatMessage $message): bool
    {
        if ($this->graph === null) {
            $this->markFailed($message, 'meta_credentials_missing');

            return false;
        }

        $account = CompanyWhatsappAccount::query()
            ->where('company_nit', $chat->company_nit)
            ->first();

        if ($account === null || ! $account->isConnected() || empty($account->phone_number_id)) {
            $this->markFailed($message, 'whatsapp_account_not_connected');

            return false;
        }

        $token = $account->accessToken();

        if (empty($token)) {
            $this->markFailed($message, 'whatsapp_access_token_missing');

            return false;
        }

        $toWaId = ltrim((string) $chat->client_phone, '+');

        if ($toWaId === '') {
            $this->markFailed($message, 'invalid_recipient');

            return false;
        }

        $result = $this->graph->sendTextMessage(
            $account->phone_number_id,
            $token,
            $toWaId,
            (string) $message->body,
        );

        if (! $result['ok']) {
            Log::channel('single')->warning('whatsapp.outbound.failed', [
                'chat_id' => $chat->id,
                'message_id' => $message->id,
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

        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $account->id,
            'event_type' => 'message_sent',
            'payload' => [
                'wa_id' => $toWaId,
                'meta_message_id' => $result['wamid'],
                'chat_id' => $chat->id,
                'chat_message_id' => $message->id,
            ],
            'created_at' => now(),
        ]);

        return true;
    }

    private function markFailed(ChatMessage $message, string $reason): void
    {
        $message->forceFill(['status' => 'failed'])->save();

        Log::channel('single')->warning('whatsapp.outbound.skipped', [
            'message_id' => $message->id,
            'reason' => $reason,
        ]);
    }
}
