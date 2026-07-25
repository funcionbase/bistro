<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Append de items del CLIENTE a su orden `pending_approval` desde la carta
 * pública (`/menus?cart={uuid}`, plan-mejoras-chat F3). Espejo de
 * `StoreBranchOrderRequest.items` — los precios NUNCA vienen del payload.
 */
class AppendCartOrderItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', new SafePlainText(maxBytes: 200, allowWhitespace: true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'El pedido no tiene productos.',
            'items.min' => 'El pedido no tiene productos.',
        ];
    }
}
