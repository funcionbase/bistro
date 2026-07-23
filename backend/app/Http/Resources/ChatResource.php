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
            // Desde cuando el cliente espera respuesta (§5.7). Es la unica
            // prioridad que le importa a un restaurante y lo que distingue "el
            // cliente espera hace 20 min" de "ya le respondimos hace 20 min",
            // que `last_message_at` mezclaba en un solo numero.
            'pending_reply_since' => $this->pending_reply_since?->toIso8601String(),
            // Por cual de mis numeros escribio. Solo el identificador y la
            // etiqueta: ni el token de la instancia ni el secreto del webhook.
            'channel' => $this->whenLoaded('whatsappAccount', fn () => $this->whatsappAccount ? [
                'id' => $this->whatsappAccount->id,
                'label' => $this->whatsappAccount->label,
                'status' => $this->whatsappAccount->status,
                'phone_e164' => $this->whatsappAccount->phone_e164,
                'can_send' => $this->whatsappAccount->canSendViaEvolution(),
            ] : null),
            // Quien mas esta mirando esta conversacion ahora mismo (§5.7). Lo
            // inyecta el controlador desde la cache; no es columna.
            'viewers' => $this->when(
                $this->viewers !== null,
                fn () => array_values((array) $this->viewers),
            ),
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
