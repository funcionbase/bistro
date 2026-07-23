<?php

namespace App\Http\Resources;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChatMessage
 */
class ChatMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender' => $this->sender,
            'body' => $this->body,
            'status' => $this->status,
            // Codigo corto (`recipient_not_on_whatsapp`, `evolution_api_error`).
            // El copy en español lo arma el frontend: el texto del proveedor
            // puede traer el numero del cliente y esto se renderiza en pantalla.
            'failure_reason' => $this->failure_reason,
            // Lo mando el dueño desde su celular, no desde el panel. La UI lo
            // rotula "desde el celular" para que un operador no crea que le
            // respondio otro operador (§8.4 punto 4).
            'from_device' => (bool) $this->from_device,
            // Autoria (§5.7). Solo el nombre: el id del usuario no le sirve a la
            // UI y es un identificador interno menos publicado.
            'author' => $this->whenLoaded('sentBy', fn () => $this->sentBy?->name),
            'media_type' => $this->media_type,
            'media_mime' => $this->media_mime,
            // Ubicacion, contacto, nombre y tamaño de archivo (§6.7). Es lo que
            // permite renderizar una tarjeta de contacto o el boton a Google
            // Maps sin re-parsear el `body`.
            'media_payload' => $this->media_payload,
            // CIBER-05: la media se sirve por endpoint autenticado (scope de
            // empresa + chats.read), no por el proxy anónimo. Si el job aún no
            // bajó el archivo, queda null y el frontend muestra placeholder
            // hasta el siguiente poll.
            'media_url' => $this->media_path
                ? route('api.chats.messages.media', ['id' => $this->chat_id, 'messageId' => $this->id])
                : null,
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
