<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\ActiveAutoApplyRequest;
use App\Http\Requests\Cart\ApplyCouponRequest;
use App\Models\CartSession;
use App\Models\Coupon;
use App\Services\CartJwtService;
use App\Services\CouponService;
use Illuminate\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Aplica un cupón durante el flujo de carrito.
 *
 * Auth dual:
 * - User JWT (operador): company_nit del JWT, order_total confiable del request.
 * - cart_jwt en body (cliente público): company_nit del CartJwt; order_total recalculado
 *   desde CartSession.items (cart_items.price * quantity). NUNCA confía en el order_total
 *   enviado por el cliente cuando llega vía CartJwt — el precio canónico vive en cart_items
 *   (snapshot del menú al momento de agregar).
 *
 * Delega la validación a CouponService::validateCoupon(); no persiste el uso (solo valida y calcula).
 * La redención real ocurre al completar la orden.
 */
class CartCouponController extends Controller
{
    public function __construct(private readonly CouponService $couponService) {}

    /**
     * Anuncia el mejor cupón auto-aplicable para el carrito actual (happy hour).
     *
     * Útil para mostrar un badge "🎉 Happy Hour activo: -20% hasta 19:00" en la UI.
     * No persiste nada; la redención ocurre al cerrar la orden, igual que en `apply()`.
     */
    public function activeAutoApply(ActiveAutoApplyRequest $request): JsonResponse
    {
        [$companyNit, $orderTotal, $clientPhone, $error] = $this->resolveContext($request);

        if ($error !== null) {
            return $error;
        }

        $result = $this->couponService->bestAutoApplyForCart(
            companyNit: $companyNit,
            totalAmount: $orderTotal,
            clientPhone: $clientPhone,
        );

        if (! $result['valid']) {
            return response()->json(['active' => false]);
        }

        $coupon = $result['coupon'];
        $endsAt = $coupon->valid_hours_to;

        return response()->json([
            'active' => true,
            'coupon_code' => $coupon->code,
            'discount_type' => $coupon->type,
            'discount_value' => (float) $coupon->value,
            'discount_amount' => (float) $result['discount_amount'],
            'original_total' => $orderTotal,
            'final_total' => (float) $result['total_after_discount'],
            'ends_at' => $endsAt !== null ? substr((string) $endsAt, 0, 5) : null,
            'label' => $this->buildLabel($coupon, $endsAt),
        ]);
    }

    private function buildLabel(Coupon $coupon, ?string $endsAt): string
    {
        $discount = $coupon->type === 'percentage'
            ? '-'.(int) $coupon->value.'%'
            : '-$'.number_format((float) $coupon->value, 0, ',', '.');

        if ($endsAt !== null) {
            return "Happy Hour activo · {$discount} hasta ".substr($endsAt, 0, 5);
        }

        return "Promo activa · {$discount}";
    }

    public function apply(ApplyCouponRequest $request): JsonResponse
    {
        [$companyNit, $orderTotal, $clientPhone, $error] = $this->resolveContext($request);

        if ($error !== null) {
            return $error;
        }

        $result = $this->couponService->validateCoupon(
            code: $request->string('coupon_code')->toString(),
            companyNit: $companyNit,
            totalAmount: $orderTotal,
            clientPhone: $clientPhone,
        );

        if (! $result['valid']) {
            return response()->json([
                'valid' => false,
                'coupon_code' => strtoupper($request->string('coupon_code')->toString()),
                'error' => $result['error'],
                'message' => 'No se pudo aplicar el cupón',
            ]);
        }

        $coupon = $result['coupon'];
        $discountAmount = $result['discount_amount'];
        $finalTotal = $result['total_after_discount'];

        $message = $coupon->type === 'percentage'
            ? "Cupón aplicado: {$coupon->value}% de descuento"
            : 'Cupón aplicado: descuento de $'.number_format($discountAmount, 0, ',', '.');

        return response()->json([
            'valid' => true,
            'coupon_code' => $coupon->code,
            'discount_type' => $coupon->type,
            'discount_value' => (float) $coupon->value,
            'discount_amount' => $discountAmount,
            'original_total' => $orderTotal,
            'final_total' => $finalTotal,
            'message' => $message,
        ]);
    }

    /**
     * Resuelve company_nit + order_total + client_phone respetando el principio
     * "el backend nunca confía en el precio del cliente cuando llega vía CartJwt".
     *
     * @return array{0: string|null, 1: float, 2: string|null, 3: JsonResponse|null}
     *                                                                               [companyNit, orderTotal, clientPhone, errorResponse]
     */
    private function resolveContext(FormRequest $request): array
    {
        $cartJwt = $request->input('cart_jwt');

        if (is_string($cartJwt) && $cartJwt !== '') {
            try {
                $service = Container::getInstance()->make(CartJwtService::class);
            } catch (RuntimeException $e) {
                Log::warning('[cart.apply-coupon] CART_JWT_SECRET no configurado.', ['error' => $e->getMessage()]);

                return [null, 0.0, null, response()->json(['valid' => false, 'error' => 'JWT de carrito inválido o expirado.'], 401)];
            }

            try {
                $payload = $service->verify($cartJwt);
            } catch (RuntimeException) {
                return [null, 0.0, null, response()->json(['valid' => false, 'error' => 'JWT de carrito inválido o expirado.'], 401)];
            }

            $session = CartSession::with('items')->where('jwt_jti', $payload['jti'])->first();

            if (! $session) {
                return [null, 0.0, null, response()->json(['valid' => false, 'error' => 'Carrito no encontrado.'], 404)];
            }

            // Recalcula el total desde el snapshot persistido en cart_items.
            // Esto blinda contra clientes maliciosos que envíen un order_total inflado
            // para obtener un descuento desproporcionado.
            $orderTotal = (float) $session->items->sum(fn ($item) => (float) $item->price * (int) $item->quantity);

            return [
                (string) $payload['company_nit'],
                $orderTotal,
                $session->client_phone ?? $request->input('client_phone'),
                null,
            ];
        }

        // Fallback: user JWT (operador). El order_total del request es confiable
        // porque la UI interna lo calcula desde la orden activa, no desde input del cliente.
        $jwtPayload = $request->attributes->get('jwt_payload');
        $companyNit = $jwtPayload['active_company_nit'] ?? null;

        if (! $companyNit) {
            return [null, 0.0, null, response()->json(['valid' => false, 'error' => 'Empresa no seleccionada.'], 400)];
        }

        return [
            $companyNit,
            (float) $request->input('order_total'),
            $request->input('client_phone'),
            null,
        ];
    }
}
