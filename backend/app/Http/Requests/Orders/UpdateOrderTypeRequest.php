<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de tipo en caliente pickup↔delivery (F5). `delivery_address` es
 * texto libre de usuario: FormRequest + `SanitizesInput` obligatorios
 * (checklist de `.claude/sanitizacion.md` — cero `validate()` inline).
 * La autorización real es el permiso `orders.update` en el controller.
 */
class UpdateOrderTypeRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'delivery_address' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_type' => ['required', Rule::in(['pickup', 'delivery'])],
            'delivery_address' => ['required_if:order_type,delivery', 'nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ];
    }
}
