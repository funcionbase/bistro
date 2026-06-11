<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Valida un cupón sin consumirlo (endpoint público para el bot de WhatsApp y la tienda web).
 *
 * No requiere JWT de usuario; recibe company_nit, total y phone como query params.
 * El campo phone se usa para verificar la restricción first_order_only via historial de pedidos.
 * Retorna 400 si el cupón no es válido (invalid, exhausted, expired, min_amount, first_order).
 * No modifica el estado del cupón; la redención se realiza por CouponRedemptionController.
 */
class CouponValidationController extends Controller
{
    public function __construct(private readonly CouponService $couponService) {}

    public function validate(Request $request, string $code): JsonResponse
    {
        $request->validate([
            'company_nit' => ['required', 'string', 'max:30'],
            'total' => ['required', 'numeric', 'min:0'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        // El `code` viene del path; lo validamos manualmente para evitar
        // entradas absurdas que terminen en una query.
        if (mb_strlen($code) > 32) {
            return response()->json(['valid' => false, 'error' => 'Codigo de cupon invalido.'], 400);
        }

        $result = $this->couponService->validateCoupon(
            code: $code,
            companyNit: $request->string('company_nit')->toString(),
            totalAmount: (float) $request->input('total'),
            clientPhone: $request->input('phone'),
        );

        if (! $result['valid']) {
            return response()->json([
                'valid' => false,
                'error' => $result['error'],
            ], 400);
        }

        $coupon = $result['coupon'];

        return response()->json([
            'valid' => true,
            'coupon' => [
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
            ],
            'discount_amount' => $result['discount_amount'],
            'total_after_discount' => $result['total_after_discount'],
        ]);
    }
}
