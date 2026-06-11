<?php

namespace App\Http\Requests\Menu;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/menus/{id}/items/{itemId}/image (MenuController). Requiere menu.update.
 *
 * Tamaño máximo: config('menu.image_max_size_kb', 2048) KB.
 * Formatos: JPG / JPEG / PNG / WEBP — los más usados en cámaras móviles y
 * compresores web. Otros (heic, gif, svg) quedan rechazados a propósito:
 * heic/gif no son universalmente soportados en navegadores y svg permite
 * embedding de scripts.
 */
class UploadDishImageRequest extends FormRequest
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
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('menu.image_max_size_kb', 2048)],
        ];
    }
}
