<?php

namespace App\Http\Requests\CashRegister;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de una entrada de efectivo a la caja activa. El permiso
 * `orders.update` se valida en la ruta y en el controller; aquí solo forma +
 * saneo del texto libre (§5 CLAUDE.md).
 */
class StoreCashIncomeRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'description' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeMappedFields();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $categories = array_keys(config('cash_register.income_categories', []));
        $methods = config('cash_register.income_payment_methods', ['cash', 'card', 'transfer']);

        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', 'string', 'in:'.implode(',', $categories)],
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', $methods)],
            'description' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
            // Multi-caja (#117): contra qué caja se carga la entrada.
            'cash_session_id' => ['nullable', 'uuid'],
        ];
    }
}
