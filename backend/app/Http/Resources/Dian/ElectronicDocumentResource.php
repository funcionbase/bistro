<?php

declare(strict_types=1);

namespace App\Http\Resources\Dian;

use App\Models\ElectronicDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ElectronicDocument
 */
class ElectronicDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_nit' => $this->company_nit,
            'branch_id' => $this->branch_id,
            'order_id' => $this->order_id,
            'dian_resolution_id' => $this->dian_resolution_id,
            'document_type' => $this->document_type,
            'prefix' => $this->prefix,
            'consecutive' => $this->consecutive,
            'full_number' => $this->full_number,
            'unique_code' => $this->unique_code,
            'unique_code_type' => $this->unique_code_type,
            'status' => $this->status,
            'environment' => $this->dian_environment_code,
            'provider_slug' => $this->provider_slug,
            'provider_track_id' => $this->provider_track_id,
            'rejection_reason' => $this->rejection_reason,
            'retry_count' => $this->retry_count,
            'qr_data' => $this->qr_data,
            // Flags para que el frontend oculte CTAs (PDF/XML) cuando el
            // documento no tiene blob físico (caso seeders demo: el doc
            // tiene CUFE/CUDE válido pero el XML/PDF no se subieron a S3).
            'has_pdf' => filled($this->pdf_path),
            'has_xml' => filled($this->xml_path),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'last_retry_at' => $this->last_retry_at?->toIso8601String(),
            'references_document_id' => $this->references_document_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
