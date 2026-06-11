<?php

declare(strict_types=1);

namespace App\Http\Resources\Dian;

use App\Models\DianDefaultRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DianDefaultRecipient
 */
class DianDefaultRecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'doc_type' => $this->doc_type,
            'doc_number' => $this->doc_number,
            'dv' => $this->dv,
            'legal_name' => $this->legal_name,
            'email' => $this->email,
            'address' => $this->address,
            'municipality_dane_code' => $this->municipality_dane_code,
            'fiscal_responsibilities' => $this->fiscal_responsibilities ?? [],
            'applies_to_auto_emit_only' => $this->applies_to_auto_emit_only,
        ];
    }
}
