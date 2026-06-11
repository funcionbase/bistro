<?php

namespace App\Http\Requests\Menu;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: PUT /api/menus/{id}/categories/{catId}/items/{itemId} (MenuController). Requiere menu.update.
 *
 * Todos los campos son opcionales (sometimes); image reemplaza la imagen existente si se provee.
 */
class UpdateItemRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'name' => 'plain_text_short',
        'description' => 'plain_text_long',
        'tax_label' => 'plain_text_short',
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
            'name' => ['sometimes', 'required', new SafePlainText(maxBytes: 128)],
            'description' => ['nullable', new SafePlainText(maxBytes: 512, allowWhitespace: true)],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'available' => ['boolean'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'tax_label' => ['sometimes', 'nullable', new SafePlainText(maxBytes: 60)],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }
}
