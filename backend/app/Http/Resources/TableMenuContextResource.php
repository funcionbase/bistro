<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Table;
use App\Models\TableSessionGuest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contexto que necesita la pantalla del menú del comensal (#191).
 *
 * Hidrata `pages/table/menu.tsx` con la identidad del comensal (resuelta por
 * la cookie `tdt_*`), la sesión de mesa, la sede y el branding. El catálogo
 * en sí lo carga el frontend desde `/api/v1/public/menu/{nit}`.
 *
 * @property-read TableSessionGuest $resource
 */
class TableMenuContextResource extends JsonResource
{
    public function __construct(
        TableSessionGuest $resource,
        private readonly Table $table,
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
        $session = $this->resource->session;

        return [
            'qrToken' => $this->qrToken,
            'nit' => $this->table->company_nit,
            'table' => [
                'number' => $this->table->number,
                'capacity' => $this->table->capacity,
            ],
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'city' => $this->branch->city,
            ],
            'company' => [
                'name' => $this->company->commercial_name ?? $this->company->name ?? '',
                'logo_url' => $this->company->logo_url ?? null,
                'primary_color' => $this->company->primary_color ?? '#0F172A',
            ],
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'opened_at' => optional($session->opened_at)?->toIso8601String(),
                'expires_at' => optional($session->expires_at)?->toIso8601String(),
            ],
            'guest' => [
                'id' => $this->resource->id,
                'display_name' => $this->resource->display_name,
                'phone_masked' => $this->maskPhone($this->resource->phone),
                'joined_at' => optional($this->resource->joined_at)?->toIso8601String(),
            ],
        ];
    }

    /** Enmascara teléfono para mostrar al cliente: 300 *** 4567. */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) !== 10) {
            return $phone;
        }

        return substr($phone, 0, 3).' *** '.substr($phone, 6);
    }
}
