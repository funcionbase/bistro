<?php

namespace App\Http\Requests\Menu;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/menus/{id}/duplicate (MenuController). Requiere menu.create.
 *
 * Sin body requerido; la lógica de duplicación se maneja íntegramente en el controlador.
 */
class DuplicateMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [];
    }
}
