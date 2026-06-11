<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'type' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('printing.types')))],
            'connection' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('printing.connections')))],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'paper_width' => ['sometimes', 'required', 'integer', Rule::in(config('printing.paper_widths'))],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
