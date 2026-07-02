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
            'media_type' => $this->media_type,
            'media_mime' => $this->media_mime,
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
