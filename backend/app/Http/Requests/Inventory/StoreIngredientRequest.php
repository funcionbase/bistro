<?php

namespace App\Http\Requests\Inventory;

use App\Services\InventoryService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: POST /api/v1/inventory/ingredients (IngredientController::store).
 * Requiere inventory.create.
 *
 * `initial_stock`/`initial_cost` son opcionales: si se pasan, el controller
 * registra un movimiento `entry` inicial vía InventoryService — nunca seedean
 * `current_stock`/`current_cost` directo (la fuente de verdad es la bitácora).
 */
class StoreIngredientRequest extends FormRequest
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
        $companyNit = $this->attributes->get('active_company_nit');

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('ingredients')->where('company_nit', $companyNit),
            ],
            'category' => ['nullable', 'string', 'max:64'],
            'unit' => ['required', 'string', Rule::in(InventoryService::VALID_UNITS)],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'warehouse_id' => ['nullable', 'string', 'uuid'],
            'initial_stock' => ['nullable', 'numeric', 'gt:0'],
            'initial_cost' => ['nullable', 'numeric', 'gt:0', 'required_with:initial_stock'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
