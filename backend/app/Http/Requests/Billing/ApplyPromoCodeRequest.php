<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de payload para aplicar un promo code desde billing-tab
 * (self-service) o desde enrollment (`?promo=...`).
 *
 * Sanitización (CLAUDE.md §5): el slug se normaliza a uppercase + trim
 * a nivel service, pero acá aplicamos `identifier` para limpiar input antes
 * de validar la regla `SafePlainText` (max 50 bytes — espejo de la columna
 * `promo_codes.code`).
 */
class ApplyPromoCodeRequest extends FormRequest
{
    use SanitizesInput;

    protected array $sanitize = [
        'code' => 'identifier',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', new SafePlainText(maxBytes: 50)],
        ];
    }
}
