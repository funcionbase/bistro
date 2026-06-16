<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/v1/inventory/ingredients/{id}/movements/adjustment.
 * Requiere inventory.update.
 *
 * Ajuste manual con motivo obligatorio. Acepta cantidad positiva o negativa
 * (no cero); se utiliza para conciliar conteos físicos o revertir movimientos
 * pasados (registrando uno opuesto, ya que la bitácora es append-only).
 */
class RecordAdjustmentRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
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
        return [
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'warehouse_id' => ['nullable', 'string', 'uuid'],
            'reference' => ['required', 'string', 'min:3', new SafePlainText(maxBytes: 255, allowWhitespace: false)],
        ];
    }
}
