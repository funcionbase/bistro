<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/v1/inventory/ingredients/{id}/movements/entry.
 * Requiere inventory.create.
 *
 * Una entrada implica recepción de mercancía: cantidad y costo unitario son
 * obligatorios. La cantidad se firma como positiva en el controller.
 */
class RecordEntryRequest extends FormRequest
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
            'unit_cost' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'string', 'uuid'],
            'reference' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: false)],
        ];
    }
}
