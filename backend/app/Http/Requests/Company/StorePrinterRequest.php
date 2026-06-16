<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrinterRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
        'address' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', new SafePlainText(maxBytes: 120, allowWhitespace: false)],
            'type' => ['required', 'string', Rule::in(array_keys(config('printing.types')))],
            'connection' => ['required', 'string', Rule::in(array_keys(config('printing.connections')))],
            'address' => ['required', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
            'paper_width' => ['required', 'integer', Rule::in(config('printing.paper_widths'))],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
