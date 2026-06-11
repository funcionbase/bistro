<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'plan' => [
                'name' => $this->plan->name,
                'price' => $this->plan->price,
                'currency' => $this->plan->currency,
                'billing_cycle' => $this->plan->billing_cycle,
            ],
        ];
    }
}
