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
            // Nombre canonico: el del contacto vinculado (fuente de verdad,
            // editable en /clients) por encima del snapshot `chats.client_name`,
            // que queda viejo al renombrar el contacto y hacia que el mismo
            // cliente se viera con nombres distintos por canal.
            'client_name' => $this->relationLoaded('contact') && $this->contact?->name
                ? $this->contact->name
                : $this->client_name,
            'contact_id' => $this->contact_id,
            'contact_notes' => $this->whenLoaded('contact', fn () => $this->contact?->notes),
            // Flag de fraude informativo (F7): historial de pedidos no recibidos.
            'contact_no_show_count' => $this->whenLoaded('contact', fn () => (int) ($this->contact?->no_show_count ?? 0)),
            'contact_fraud_flagged_at' => $this->whenLoaded('contact', fn () => $this->contact?->fraud_flagged_at?->toIso8601String()),
            // Dirección del contacto para prellenar el editor de "editar Contacto".
            'contact_address' => $this->whenLoaded('contact', fn () => $this->contact?->address),
            'contact_neighborhood' => $this->whenLoaded('contact', fn () => $this->contact?->neighborhood),
            'contact_municipality_dane_code' => $this->whenLoaded('contact', fn () => $this->contact?->municipality_dane_code),
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
            // Panel de próxima acción (F4): última sesión de carta + sus
            // órdenes con guard de recibo. Lo inyecta show(); no es columna.
            'cart_flow' => $this->when($this->cart_flow !== null, fn () => $this->cart_flow),
        ];
    }
}
