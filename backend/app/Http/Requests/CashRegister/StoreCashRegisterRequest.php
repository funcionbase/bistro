<?php

namespace App\Http\Requests\CashRegister;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de una caja física en la sede activa (multi-caja). El permiso
 * `cash_register.manage` se valida en la ruta; aquí solo forma + saneo.
 */
class StoreCashRegisterRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', new SafePlainText(maxBytes: 120)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
