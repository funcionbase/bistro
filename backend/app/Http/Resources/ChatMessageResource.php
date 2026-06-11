<?php

namespace App\Http\Resources;

use App\Models\ChatMessage;
use App\Support\SignedAssetUrl;
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
            // Si ya bajamos el archivo (job termino), exponemos URL publica.
            // Si no, queda null y el frontend muestra un placeholder hasta el
            // siguiente poll que ya tendra la URL lista.
            'media_url' => SignedAssetUrl::for($this->media_path),
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
