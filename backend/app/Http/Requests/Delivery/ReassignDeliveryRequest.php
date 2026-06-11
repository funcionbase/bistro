<?php

namespace App\Http\Requests\Delivery;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/deliveries/{id}/reassign (DeliveryStatusController::reassign). Requiere deliveries.update.
 *
 * El controlador valida que el nuevo repartidor sea diferente al actual y que la entrega esté en 'pending'.
 */
class ReassignDeliveryRequest extends FormRequest
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
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
