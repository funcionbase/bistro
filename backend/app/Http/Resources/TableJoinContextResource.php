<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contexto que necesita la pantalla pública de unión a mesa.
 *
 * Hidrata `pages/table/join.tsx` con la mesa, la sede y el branding del
 * restaurante resueltos desde el `qr_token`. Sin auth — el flujo es público.
 *
 * @property-read Table $resource
 */
class TableJoinContextResource extends JsonResource
{
    public function __construct(
        Table $resource,
        private readonly Branch $branch,
        private readonly Company $company,
        private readonly string $qrToken,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'qrToken' => $this->qrToken,
            'table' => [
                'number' => $this->resource->number,
                'capacity' => $this->resource->capacity,
            ],
            'branch' => [
                'name' => $this->branch->name,
                'city' => $this->branch->city,
            ],
            'company' => [
                'name' => $this->company->commercial_name ?? $this->company->name ?? '',
                'logo_url' => $this->company->logo_url ?? null,
                'primary_color' => $this->company->primary_color ?? '#0F172A',
            ],
            'phoneRegexHint' => '3001234567',
        ];
    }
}
