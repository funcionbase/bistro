<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Jobs\DispatchAutomationWebhookJob;
use App\Models\AutomationFlow;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\CompanyWhatsappAccount;
use Illuminate\Support\Str;

/**
 * F6 (§9.2) — punto único de emisión del push saliente a n8n.
 *
 * Resuelve el flujo que aplica (sede→empresa), filtra por eventos suscritos, arma
 * el cuerpo firmado y encola `DispatchAutomationWebhookJob`. Si no hay flujo
 * habilitado o el evento no está suscrito, no emite nada — la ausencia de
 * automatización es el estado por defecto (§5.6), no un error.
 *
 * Anti-loop (§9.2): `chat.message.received` NUNCA se emite para `sender='bot'` —
 * la respuesta del bot dispararía otro webhook y el flujo se mordería la cola.
 */
class AutomationDispatcher
{
    public const EVENT_MESSAGE_RECEIVED = 'chat.message.received';

    public const EVENT_HANDOFF_REQUESTED = 'chat.handoff.requested';

    public const EVENT_BOT_TOGGLED = 'chat.bot_toggled';

    public const EVENT_CHANNEL_STATUS = 'channel.status.changed';

    /**
     * Evento originado en un chat. `message` se incluye solo si viene.
     *
     * @param  array<string, mixed>  $extra
     */
    public function forChat(string $event, Chat $chat, ?ChatMessage $message = null, array $extra = []): void
    {
        // Anti-loop: la respuesta del bot no genera un nuevo "recibido".
        if ($event === self::EVENT_MESSAGE_RECEIVED && $message !== null && $message->sender === 'bot') {
            return;
        }

        $flow = AutomationFlow::resolveForChat($chat);
        if (! $this->subscribed($flow, $event)) {
            return;
        }

        $channel = $chat->resolveWhatsappChannel();

        $body = [
            'event' => $event,
            'sent_at' => now()->toIso8601String(),
            'company_nit' => $chat->company_nit,
            'branch_id' => $chat->branch_id,
            'channel' => $this->channelBlock($channel),
            'chat' => [
                'id' => $chat->id,
                'client_phone' => $chat->client_phone,
                'client_name' => $chat->client_name,
                'bot_paused' => (bool) $chat->bot_paused,
            ],
        ] + $extra;

        if ($message !== null) {
            $body['message'] = [
                'id' => $message->id,
                'sender' => $message->sender,
                'body' => $message->body,
                'media_type' => $message->media_type,
                'sent_at' => $message->sent_at?->toIso8601String(),
            ];
        }

        $this->dispatch($flow, $channel?->id, $event, $body);
    }

    /**
     * Evento originado en un canal (no en un chat): `channel.status.changed`.
     *
     * @param  array<string, mixed>  $extra
     */
    public function forChannel(string $event, CompanyWhatsappAccount $channel, array $extra = []): void
    {
        $flow = AutomationFlow::resolveForScope($channel->company_nit, $channel->branch_id);
        if (! $this->subscribed($flow, $event)) {
            return;
        }

        $body = [
            'event' => $event,
            'sent_at' => now()->toIso8601String(),
            'company_nit' => $channel->company_nit,
            'branch_id' => $channel->branch_id,
            'channel' => $this->channelBlock($channel),
        ] + $extra;

        $this->dispatch($flow, $channel->id, $event, $body);
    }

    /**
     * Un flujo cubre el evento si existe, está habilitado y (lista de eventos
     * vacía = todos) o el evento está en su suscripción.
     */
    private function subscribed(?AutomationFlow $flow, string $event): bool
    {
        if ($flow === null || ! $flow->enabled) {
            return false;
        }

        $events = $flow->events ?? [];

        return $events === [] || in_array($event, $events, true);
    }

    /**
     * @return array{id: ?string, label: ?string, phone_e164: ?string}
     */
    private function channelBlock(?CompanyWhatsappAccount $channel): array
    {
        return [
            'id' => $channel?->id,
            'label' => $channel?->label,
            'phone_e164' => $channel?->phone_e164,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function dispatch(AutomationFlow $flow, ?string $channelId, string $event, array $body): void
    {
        DispatchAutomationWebhookJob::dispatch(
            flowId: $flow->id,
            channelId: $channelId,
            event: $event,
            body: $body,
            deliveryId: (string) Str::uuid(),
        );
    }
}
