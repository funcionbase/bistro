<?php

declare(strict_types=1);

namespace App\Http\Requests\Dian;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmitDocumentRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        $types = (array) config('dian.document_types_allowed', []);
        $companyNit = (string) $this->attributes->get('active_company_nit');
        $branchId = $this->attributes->get('active_branch_id');

        return [
            // order_id debe existir Y pertenecer a la empresa activa del JWT.
            // Defensa: aunque el controller también valida, esta regla rechaza
            // antes de pasar al lock + cualquier escritura.
            'order_id' => [
                'required',
                'uuid',
                Rule::exists('orders', 'id')->where(function ($q) use ($companyNit, $branchId) {
                    $q->where('company_nit', $companyNit);
                    if ($branchId !== null) {
                        $q->where('branch_id', $branchId);
                    }
                }),
            ],
            'document_type' => ['required', 'string', 'in:'.implode(',', $types)],
            // references_document_id (cuando es NC/ND) también restringido a la empresa.
            'references_document_id' => [
                'nullable',
                'uuid',
                Rule::exists('electronic_documents', 'id')->where(function ($q) use ($companyNit) {
                    $q->where('company_nit', $companyNit);
                }),
            ],
            'force_print' => ['nullable', 'boolean'],
            'printer_id' => [
                'nullable',
                'uuid',
                Rule::exists('printers', 'id')->where(function ($q) use ($branchId) {
                    $q->where('is_active', true);
                    if ($branchId !== null) {
                        $q->where('branch_id', $branchId);
                    }
                }),
            ],
        ];
    }
}
