<?php

namespace App\Http\Requests\Coupon;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PATCH /api/coupons/{id}/status (CouponController::updateStatus). Requiere coupons.update.
 *
 * Estados válidos: active, inactive, exhausted. El status 'exhausted' lo asigna el sistema automáticamente
 * al agotarse max_uses; solo se incluye aquí para permitir correcciones manuales.
 */
class UpdateStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'exhausted'])],
        ];
    }
}
