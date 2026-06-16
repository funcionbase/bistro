<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
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
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
        'category' => 'plain_text_short',
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
        $companyNit = $this->attributes->get('active_company_nit');

        return [
            'name' => [
                'required',
                new SafePlainText(maxBytes: 150, allowWhitespace: false),
                Rule::unique('ingredients')->where('company_nit', $companyNit),
            ],
            'category' => ['nullable', new SafePlainText(maxBytes: 64, allowWhitespace: false)],
            'unit' => ['required', 'string', Rule::in(InventoryService::VALID_UNITS)],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'warehouse_id' => ['nullable', 'string', 'uuid'],
            'initial_stock' => ['nullable', 'numeric', 'gt:0'],
            'initial_cost' => ['nullable', 'numeric', 'gt:0', 'required_with:initial_stock'],
            'reference' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: false)],
        ];
    }
}
