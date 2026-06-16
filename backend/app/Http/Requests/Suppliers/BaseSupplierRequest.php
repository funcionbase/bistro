<?php

namespace App\Http\Requests\Suppliers;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
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
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
        'document_number' => 'plain_text_short',
        'contact_name' => 'plain_text_short',
        'address' => 'plain_text_long',
        'notes' => 'plain_text_long',
    ];

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
            ? ['sometimes', 'required', new SafePlainText(maxBytes: 150, allowWhitespace: false)]
            : ['required', new SafePlainText(maxBytes: 150, allowWhitespace: false)];

        return [
            'name' => $nameRule,
            'document_type' => ['nullable', Rule::in(['NIT', 'CC', 'CE', 'PAS', 'OTRO'])],
            'document_number' => ['nullable', new SafePlainText(maxBytes: 32, allowWhitespace: false), $uniqueRule],
            'contact_name' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', new SafePlainText(maxBytes: 2000, allowWhitespace: true)],
        ];
    }
}
