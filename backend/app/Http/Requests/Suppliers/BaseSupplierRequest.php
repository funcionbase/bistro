<?php

namespace App\Http\Requests\Suppliers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas compartidas entre StoreSupplierRequest y UpdateSupplierRequest.
 *
 * Patrón: la subclase implementa `isUpdate()` y `existingId()` (en update,
 * devuelve el id de la ruta para que Rule::unique lo ignore). En store
 * devuelven false / null y las reglas se aplican con `required` puro.
 */
abstract class BaseSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    abstract protected function isUpdate(): bool;

    abstract protected function existingId(): ?string;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $companyNit = $this->attributes->get('active_company_nit');

        $uniqueRule = Rule::unique('suppliers')
            ->where('company_nit', $companyNit)
            ->whereNotNull('document_number');

        if ($this->isUpdate() && $this->existingId() !== null) {
            $uniqueRule->ignore($this->existingId());
        }

        $nameRule = $this->isUpdate()
            ? ['sometimes', 'required', 'string', 'max:150']
            : ['required', 'string', 'max:150'];

        return [
            'name' => $nameRule,
            'document_type' => ['nullable', Rule::in(['NIT', 'CC', 'CE', 'PAS', 'OTRO'])],
            'document_number' => ['nullable', 'string', 'max:32', $uniqueRule],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
