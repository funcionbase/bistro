<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use App\Services\InventoryService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PATCH /api/v1/inventory/ingredients/{id} (IngredientController::update).
 * Requiere inventory.update.
 *
 * Actualiza solo metadatos (nombre, categoría, unidad, umbral mínimo). Stock y
 * costo NO se modifican aquí — se mutan exclusivamente vía movimientos.
 */
class UpdateIngredientRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
        'category' => 'plain_text_short',
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
        // El id de ingredients es UUID (HasUuids). Castearlo a (int) producía
        // basura (ej. "19e4-..." → 19) y rompía el unique...ignore con
        // "invalid input syntax for type uuid". Se mantiene como string.
        $id = (string) $this->route('id');

        return [
            'name' => [
                'sometimes',
                'required',
                new SafePlainText(maxBytes: 150, allowWhitespace: false),
                Rule::unique('ingredients')->where('company_nit', $companyNit)->ignore($id),
            ],
            'category' => ['sometimes', 'nullable', new SafePlainText(maxBytes: 64, allowWhitespace: false)],
            'unit' => ['sometimes', 'required', 'string', Rule::in(InventoryService::VALID_UNITS)],
            'min_stock' => ['sometimes', 'required', 'numeric', 'min:0'],
        ];
    }
}
