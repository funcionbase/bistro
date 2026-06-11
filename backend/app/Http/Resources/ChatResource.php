<?php

namespace App\Http\Resources;

use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estructura del chat para el panel de conversaciones.
 *
 * Si la relacion `messages` esta cargada, incluye el array completo de mensajes.
 * Si solo `latestMessage` esta cargada, expone unicamente el ultimo mensaje como preview.
 *
 * @mixin Chat
 */
class ChatResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_phone' => $this->client_phone,
            'client_name' => $this->client_name,
            'contact_id' => $this->contact_id,
            'contact_notes' => $this->whenLoaded('contact', fn () => $this->contact?->notes),
            'status' => $this->status,
            'source' => $this->source ?? 'whatsapp',
            'bot_paused' => (bool) $this->bot_paused,
            'handoff_requested_at' => $this->handoff_requested_at?->toIso8601String(),
            'handoff_reason' => $this->handoff_reason,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message' => $this->whenLoaded(
                'latestMessage',
                fn () => $this->latestMessage->first()
                    ? new ChatMessageResource($this->latestMessage->first())
                    : null
            ),
            'messages' => ChatMessageResource::collection($this->whenLoaded('messages')),
            'latest_order' => $this->latest_order_id !== null
                ? ['id' => (string) $this->latest_order_id, 'status' => (string) $this->latest_order_status]
                : null,
        ];
    }
}
