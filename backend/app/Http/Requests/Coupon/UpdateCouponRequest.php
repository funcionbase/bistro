<?php

namespace App\Http\Requests\Coupon;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PUT /api/coupons/{id} (CouponController::update). Requiere coupons.update.
 *
 * El controlador bloquea actualizaciones si uses_count > 0 (cupón ya usado es inmutable).
 * El código no es actualizable una vez creado (no incluido en rules).
 */
class UpdateCouponRequest extends FormRequest
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
            'type' => ['sometimes', 'string', Rule::in(['percentage', 'fixed_amount'])],
            'value' => ['sometimes', 'numeric', 'gt:0'],
            'valid_from' => ['nullable', 'date', 'before_or_equal:valid_until'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'first_order_only' => ['nullable', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_days' => ['nullable', 'array'],
            'valid_days.*' => ['integer', 'between:0,6'],
            'valid_hours_from' => ['nullable', 'required_with:valid_hours_to', 'date_format:H:i'],
            'valid_hours_to' => ['nullable', 'required_with:valid_hours_from', 'date_format:H:i'],
            'auto_apply' => ['nullable', 'boolean'],
        ];
    }
}
