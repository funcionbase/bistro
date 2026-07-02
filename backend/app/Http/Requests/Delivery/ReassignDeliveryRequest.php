<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: POST /api/deliveries/{id}/reassign (DeliveryStatusController::reassign). Requiere deliveries.update.
 *
 * El controlador valida que el nuevo repartidor sea diferente al actual y que la entrega esté en 'pending'.
 */
class ReassignDeliveryRequest extends FormRequest
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
            // CIBER-01: courier scopeado a la empresa activa (era exists global).
            'user_id' => ['required', 'uuid', Rule::exists('company_users', 'user_id')
                ->where('company_nit', $this->attributes->get('active_company_nit'))],
            'reason' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
        ];
    }
}
