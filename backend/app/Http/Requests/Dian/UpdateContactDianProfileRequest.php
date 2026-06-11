<?php

declare(strict_types=1);

namespace App\Http\Requests\Dian;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContactDianProfileRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'doc_number' => 'identifier',
        'dv' => 'identifier',
        'legal_name' => 'plain_text_short',
        'email' => 'plain_text_short',
        'address' => 'plain_text_short',
        'municipality_dane_code' => 'identifier',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        $docTypes = array_keys((array) config('dian.doc_types_catalog'));
        $fiscalResponsibilities = array_keys((array) config('dian.fiscal_responsibilities_catalog'));

        return [
            'doc_type' => ['required', 'string', 'in:'.implode(',', $docTypes)],
            'doc_number' => ['required', new SafePlainText(maxBytes: 30, allowWhitespace: false)],
            'dv' => ['nullable', 'string', 'regex:/^[0-9]$/'],
            'legal_name' => ['required', new SafePlainText(maxBytes: 200, allowWhitespace: true)],
            'email' => ['nullable', 'email', 'max:200'],
            'address' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'municipality_dane_code' => ['nullable', 'string', 'regex:/^[0-9]{5}$/'],
            'fiscal_responsibilities' => ['nullable', 'array'],
            'fiscal_responsibilities.*' => ['string', 'in:'.implode(',', $fiscalResponsibilities)],
        ];
    }
}
