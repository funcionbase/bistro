<?php

namespace App\Http\Requests\Menu;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/menus/{id}/categories/{catId}/items (MenuController). Requiere menu.update.
 *
 * image: opcional, JPG/PNG, máx 2MB. El precio mínimo es 0.
 */
class StoreItemRequest extends FormRequest
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
            'name' => ['required', new SafePlainText(maxBytes: 128)],
            'description' => ['nullable', new SafePlainText(maxBytes: 512, allowWhitespace: true)],
            'price' => ['required', 'numeric', 'min:0'],
            // Costo unitario opcional para cálculo de margen. Sensible: nunca se
            // expone al menú público (showPublic strip-ea este campo).
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'available' => ['boolean'],
            // Override tributario por ítem (opcional). Si null/ausente hereda de
            // companies.default_tax_rate. Útil para menús mixtos (e.g., bebida
            // alcohólica IVA 19% mientras la comida lleva INC 8%).
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_label' => ['nullable', new SafePlainText(maxBytes: 60)],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }
}
