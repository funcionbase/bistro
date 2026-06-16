<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'notes' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', new SafePlainText(maxBytes: 2000, allowWhitespace: true)],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required_with:items', 'uuid', 'exists:ingredients,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
