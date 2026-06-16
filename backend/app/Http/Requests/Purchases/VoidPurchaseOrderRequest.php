<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VoidPurchaseOrderRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'reason' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ];
    }
}
