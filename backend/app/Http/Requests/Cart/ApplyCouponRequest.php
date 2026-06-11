<?php

namespace App\Http\Requests\Cart;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint: POST /api/v1/cart/apply-coupon (CartCouponController::apply).
 *
 * Auth dual:
 * - User JWT (operador): se confía en order_total enviado por el cliente (UI interna).
 * - cart_jwt (CartJwtService) opcional en body: el backend recalcula order_total desde
 *   CartSession.items, ignorando lo que envíe el cliente. Defense-in-depth — el precio
 *   canónico vive en cart_items (snapshot del menú al agregar). El cliente público nunca
 *   debe poder dictar el total a descontar.
 *
 * client_phone: opcional; necesario para validar la restricción first_order_only.
 */
class ApplyCouponRequest extends FormRequest
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
        // order_total queda opcional: si se provee cart_jwt, el controlador lo recalcula desde CartSession.
        return [
            'coupon_code' => ['required', 'string', 'max:32'],
            'order_total' => ['required_without:cart_jwt', 'numeric', 'min:0'],
            'client_phone' => ['nullable', 'string', 'max:30'],
            'cart_jwt' => ['nullable', 'string'],
        ];
    }
}
