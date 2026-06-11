<?php

namespace App\Http\Requests\Menu;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: PUT /api/menus/{id}/schedule (MenuController). Requiere menu.update.
 *
 * active_days: array de días (0=domingo … 6=sábado, convención Carbon). Null/vacío = sin programación (menú manual).
 */
class ScheduleMenuRequest extends FormRequest
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
            'active_days' => ['nullable', 'array'],
            'active_days.*' => ['integer', 'between:0,6'],
        ];
    }
}
