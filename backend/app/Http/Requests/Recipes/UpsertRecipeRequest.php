<?php

namespace App\Http\Requests\Recipes;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PUT /api/v1/menus/{menu}/items/{itemId}/recipe.
 *
 * Reemplaza el set completo de líneas de receta. Las filas existentes se
 * soft-archive y se insertan las nuevas en una transacción.
 *
 * - `items` puede venir vacío para "limpiar" la receta del ítem (todas las
 *   filas activas pasan a archivadas).
 * - Tenant isolation y compatibilidad de unidades se validan en el controller
 *   contra los `ingredient_id` reales de la empresa (no aquí, para evitar N
 *   queries por field validator).
 */
class UpsertRecipeRequest extends FormRequest
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
            'items' => ['present', 'array'],
            'items.*.ingredient_id' => ['required', 'uuid'],
            'items.*.warehouse_id' => ['nullable', 'string', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['required', 'string', Rule::in(config('menu.recipe.units'))],
        ];
    }
}
