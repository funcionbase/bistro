<?php

namespace App\Http\Requests\Cart;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/v1/cart/active-auto-apply (CartCouponController::activeAutoApply).
 *
 * Mismo auth dual que ApplyCouponRequest, pero sin `coupon_code`: este endpoint
 * solo anuncia cupones auto-aplicables programados (happy hour) y no recibe
 * código. order_total opcional cuando viene cart_jwt (el controlador recalcula
 * desde CartSession.items para evitar manipulación del cliente público).
 */
class ActiveAutoApplyRequest extends FormRequest
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
            'order_total' => ['required_without:cart_jwt', 'numeric', 'min:0'],
            'client_phone' => ['nullable', 'string', 'max:30'],
            'cart_jwt' => ['nullable', 'string'],
        ];
    }
}
