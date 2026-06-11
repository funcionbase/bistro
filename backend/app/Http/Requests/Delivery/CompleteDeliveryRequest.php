<?php

namespace App\Http\Requests\Delivery;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: PATCH /api/deliveries/{id}/complete (DeliveryController::complete). Requiere deliveries.update.
 *
 * Sin body requerido; la lógica de completado (duration_minutes, estado) se maneja en DeliveryService.
 */
class CompleteDeliveryRequest extends FormRequest
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
        return [];
    }
}
