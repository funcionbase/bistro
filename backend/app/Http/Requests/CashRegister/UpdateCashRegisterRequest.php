<?php

namespace App\Http\Requests\CashRegister;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Renombrar / activar-desactivar / archivar una caja (multi-caja). El
 * permiso `cash_register.manage` se valida en la ruta. Archivar es el "borrado"
 * contable (no se elimina físicamente para preservar FKs históricas).
 */
class UpdateCashRegisterRequest extends FormRequest
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
            'name' => ['sometimes', 'required', new SafePlainText(maxBytes: 120)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
