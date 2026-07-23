<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChatQuickReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta rápida como la ve el menú `/` del compositor (§8.4b punto 7).
 *
 * @mixin ChatQuickReply
 */
class ChatQuickReplyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            // `null` = de toda la empresa; con nombre = de esa sede. El frontend
            // lo muestra como etiqueta para que el operador sepa el alcance.
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
