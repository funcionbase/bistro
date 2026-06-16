<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/deliveries/{id}/assign (DeliveryController::assignCourier). Requiere deliveries.update.
 */
class AssignCourierRequest extends FormRequest
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
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'reason' => ['nullable', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
        ];
    }
}
