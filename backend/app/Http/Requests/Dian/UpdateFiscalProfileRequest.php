<?php

declare(strict_types=1);

namespace App\Http\Requests\Dian;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFiscalProfileRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'legal_representative_name' => 'plain_text_short',
        'legal_representative_doc_number' => 'identifier',
        'economic_activity_code' => 'identifier',
        'municipality_dane_code' => 'identifier',
        'billing_email' => 'plain_text_short',
        'billing_phone' => 'identifier',
        'physical_address' => 'plain_text_short',
        'dv' => 'identifier',
    ];

    public function authorize(): bool
    {
        return true; // permission middleware ya validó
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        $docTypes = array_keys((array) config('dian.doc_types_catalog'));
        $fiscalResponsibilities = array_keys((array) config('dian.fiscal_responsibilities_catalog'));

        return [
            'dv' => ['nullable', 'string', 'regex:/^[0-9]$/'],
            'legal_representative_name' => ['nullable', new SafePlainText(maxBytes: 200, allowWhitespace: true)],
            'legal_representative_doc_type' => ['nullable', 'string', 'in:'.implode(',', $docTypes)],
            'legal_representative_doc_number' => ['nullable', new SafePlainText(maxBytes: 30, allowWhitespace: false)],
            'economic_activity_code' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'fiscal_responsibilities' => ['nullable', 'array'],
            'fiscal_responsibilities.*' => ['string', 'in:'.implode(',', $fiscalResponsibilities)],
            'tax_obligations' => ['nullable', 'array'],
            'tax_obligations.*' => ['string', 'max:32'],
            'municipality_dane_code' => ['nullable', 'string', 'regex:/^[0-9]{5}$/'],
            'billing_email' => ['nullable', 'email', 'max:200'],
            'billing_phone' => ['nullable', new SafePlainText(maxBytes: 30, allowWhitespace: false)],
            'physical_address' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'country_code' => ['nullable', 'string', 'size:2'],
        ];
    }
}
