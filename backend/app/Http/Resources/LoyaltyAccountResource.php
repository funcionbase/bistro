<?php

namespace App\Http\Resources;

use App\Services\LoyaltyService;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DTO de cuenta de fidelización. Incluye progreso al siguiente tier
 * calculado a partir del catálogo de tiers de la empresa.
 */
class LoyaltyAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LoyaltyService $loyalty */
        $loyalty = Container::getInstance()->make(LoyaltyService::class);
        $progress = $loyalty->tierProgress($this->company_nit, (int) $this->lifetime_earned);

        return [
            'id' => $this->id,
            'company_nit' => $this->company_nit,
            'client_phone' => $this->client_phone,
            'balance' => (int) $this->balance,
            'lifetime_earned' => (int) $this->lifetime_earned,
            'tier' => $this->tier,
            'tier_progress' => $progress,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'movements' => $this->whenLoaded('movements', fn () => LoyaltyMovementResource::collection($this->movements)),
        ];
    }
}
