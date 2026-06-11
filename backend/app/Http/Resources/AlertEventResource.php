<?php

namespace App\Http\Resources;

use App\Models\AlertEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AlertEvent
 */
class AlertEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule_id' => $this->alert_rule_id,
            'type' => $this->type,
            'severity' => $this->severity,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'payload' => $this->payload,
            'status' => $this->status,
            'triggered_at' => $this->triggered_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
            'actioned_at' => $this->actioned_at?->toIso8601String(),
            'actioned_note' => $this->actioned_note,
            'actioned_by' => $this->actioned_by,
        ];
    }
}
