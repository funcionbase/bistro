<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(array_keys(config('printing.types')))],
            'connection' => ['required', 'string', Rule::in(array_keys(config('printing.connections')))],
            'address' => ['required', 'string', 'max:255'],
            'paper_width' => ['required', 'integer', Rule::in(config('printing.paper_widths'))],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
