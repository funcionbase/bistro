<?php

declare(strict_types=1);

namespace App\Http\Resources\Dian;

use App\Models\DianResolution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DianResolution
 */
class DianResolutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'prefix' => $this->prefix,
            'range_from' => $this->range_from,
            'range_to' => $this->range_to,
            'current_number' => $this->current_number,
            'resolution_number' => $this->resolution_number,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'environment' => $this->environment,
            'is_active' => $this->is_active,
            'is_expiring_soon' => $this->isExpiringSoon(),
            'is_exhausted' => $this->isExhausted(),
            // technical_key NUNCA en GET — solo se envía en el body del POST/PUT
            // que la rote.
        ];
    }
}
