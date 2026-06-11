<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in((array) config('purchases.payment_methods'))],
            'payment_reference' => ['nullable', 'string', 'max:120', 'required_unless:payment_method,cash'],
        ];
    }
}
