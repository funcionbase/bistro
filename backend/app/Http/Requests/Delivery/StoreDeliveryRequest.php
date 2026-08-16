<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: POST /api/deliveries (DeliveryController::store). Requiere deliveries.create.
 *
 * El controlador valida que la orden no tenga ya una entrega activa (invariante single active delivery).
 */
class StoreDeliveryRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'reason' => 'plain_text_long',
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
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
            // `exists:users,id` era global (cross-tenant). El courier
            // debe ser miembro de la empresa activa; si no, el user_id es
            // inválido (no revela usuarios de otras empresas).
            'user_id' => ['required', 'uuid', Rule::exists('company_users', 'user_id')
                ->where('company_nit', $this->attributes->get('active_company_nit'))],
            'reason' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
        ];
    }
}
