<?php

namespace App\Http\Requests\Inventory;

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
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
