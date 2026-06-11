<?php

namespace App\Http\Requests\Inventory;

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
            'reference' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
