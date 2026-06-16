<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/v1/inventory/ingredients/{id}/movements/waste.
 * Requiere inventory.create.
 *
 * Merma: la cantidad sale del inventario (firma negativa en el controller).
 * El motivo (`reference`) es obligatorio para trazabilidad.
 */
class RecordWasteRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'reference' => 'plain_text_short',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'string', 'uuid'],
            'reference' => ['required', 'string', 'min:3', new SafePlainText(maxBytes: 255, allowWhitespace: false)],
        ];
    }
}
