<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutomationFlow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista pública de un flujo de automatización para el panel (§9.5).
 *
 * NUNCA expone `token_hash` ni `secret_encrypted`: el token en claro solo se ve
 * una vez al generarlo/rotarlo (patrón PAT), y el secreto de firma igual. Acá
 * solo van los últimos 4 del token y la fecha, para que la UI muestre `ffw_…a1b2`.
 *
 * @mixin AutomationFlow
 */
class AutomationFlowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'label' => $this->label,
            'enabled' => (bool) $this->enabled,
            'url' => $this->url,
            'events' => $this->events ?? [],
            // El token existe pero no se muestra: solo su cola y cuándo se creó.
            'has_token' => $this->token_hash !== null,
            'token_last4' => $this->token_last4,
            'token_created_at' => $this->token_created_at?->toIso8601String(),
            'last_delivery_at' => $this->last_delivery_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
