<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\LoyaltyRedemption;
use App\Models\Order;

/**
 * Orquesta la validación, cálculo y redención de cupones de descuento.
 *
 * La validación de first_order_only se basa en el historial de pedidos del client_phone.
 * La redención (redeemCoupon) crea un registro inmutable en coupon_redemptions e incrementa uses_count.
 * Cuando uses_count alcanza max_uses, el cupón se marca automáticamente como 'exhausted'.
 * La lógica de validación completa de reglas de negocio vive en Coupon::isValidFor().
 */
class CouponService
{
    /**
     * @return array{valid: bool, coupon: Coupon|null, discount_amount: float, total_after_discount: float, error: string|null}
     */
    public function validateCoupon(string $code, string $companyNit, float $totalAmount, ?string $clientPhone, ?string $branchId = null): array
    {
        $coupon = Coupon::forCompany($companyNit)
            ->where('code', strtoupper($code))
            ->first();

        if (! $coupon) {
            return ['valid' => false, 'coupon' => null, 'discount_amount' => 0, 'total_after_discount' => $totalAmount, 'error' => 'Cupón no encontrado'];
        }

        $validation = $coupon->isValidFor($totalAmount, $clientPhone);

        if (! $validation['valid']) {
            return ['valid' => false, 'coupon' => $coupon, 'discount_amount' => 0, 'total_after_discount' => $totalAmount, 'error' => $validation['error']];
        }

        // Multi-sede: si llamamos con sede activa, validar scope.
        // Las llamadas anónimas (bot público) no pasan branchId y se omite.
        if ($branchId !== null) {
            $scopeOk = match ($coupon->scope ?? 'branch') {
                'branch' => $coupon->branch_id === $branchId,
                'company' => $coupon->valid_in_branches === null
                    || in_array($branchId, (array) $coupon->valid_in_branches, true),
                default => false,
            };

            if (! $scopeOk) {
                return [
                    'valid' => false,
                    'coupon' => $coupon,
                    'discount_amount' => 0,
                    'total_after_discount' => $totalAmount,
                    'error' => 'Este cupón no aplica para esta sede.',
                ];
            }
        }

        $discountAmount = $coupon->calculateDiscount($totalAmount);
        $totalAfterDiscount = max(0, $totalAmount - $discountAmount);

        return [
            'valid' => true,
            'coupon' => $coupon,
            'discount_amount' => $discountAmount,
            'total_after_discount' => $totalAfterDiscount,
            'error' => null,
        ];
    }

    public function calculateDiscount(Coupon $coupon, float $totalAmount): float
    {
        return $coupon->calculateDiscount($totalAmount);
    }

    /**
     * Auto-aplica el mejor cupón programado para el carrito actual (happy hour).
     *
     * Filtra cupones de la empresa con `auto_apply = true` y status='active', valida
     * cada uno (vigencia, programación, scope, monto mínimo, etc.) y devuelve el de
     * mayor `discount_amount`. Desempate: `created_at DESC`. No persiste nada.
     *
     * Excluye cupones con `locked_to_phone` (son de canje de fidelización y deben
     * ser invocados explícitamente) y los de `source = 'loyalty_redeem'`.
     *
     * @return array{valid: bool, coupon: Coupon|null, discount_amount: float, total_after_discount: float, error: string|null}
     */
    public function bestAutoApplyForCart(string $companyNit, float $totalAmount, ?string $clientPhone, ?string $branchId = null): array
    {
        $candidates = Coupon::forCompany($companyNit)
            ->active()
            ->where('auto_apply', true)
            ->whereNull('locked_to_phone')
            ->where('source', '!=', 'loyalty_redeem')
            ->get();

        $best = null;
        foreach ($candidates as $coupon) {
            $result = $this->validateCoupon(
                code: $coupon->code,
                companyNit: $companyNit,
                totalAmount: $totalAmount,
                clientPhone: $clientPhone,
                branchId: $branchId,
            );

            if (! $result['valid']) {
                continue;
            }

            if ($best === null || $result['discount_amount'] > $best['discount_amount']) {
                $best = $result;
            }
        }

        return $best ?? [
            'valid' => false,
            'coupon' => null,
            'discount_amount' => 0.0,
            'total_after_discount' => $totalAmount,
            'error' => null,
        ];
    }

    public function redeemCoupon(Coupon $coupon, Order $order, ?string $clientPhone, float $discountAmount, float $orderTotalBefore, float $orderTotalAfter): CouponRedemption
    {
        $redemption = CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'company_nit' => $coupon->company_nit,
            // Multi-sede: la redención se inscribe en la sede de la orden,
            // NO la del cupón (que puede ser company-scope cubriendo varias).
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'client_phone' => $clientPhone,
            'discount_amount' => $discountAmount,
            'order_total_before' => $orderTotalBefore,
            'order_total_after' => $orderTotalAfter,
            'created_at' => now(),
        ]);

        $coupon->incrementUsage();

        // Fidelización: si el cupón viene de un canje de puntos, marcamos
        // la redemption asociada como 'applied' y fijamos applied_order_id. Una
        // vez aplicado, status es inmutable (consistente con append-only).
        if ($coupon->source === 'loyalty_redeem') {
            LoyaltyRedemption::where('coupon_id', $coupon->id)
                ->where('status', LoyaltyRedemption::STATUS_ISSUED)
                ->update([
                    'status' => LoyaltyRedemption::STATUS_APPLIED,
                    'applied_at' => now(),
                    'applied_order_id' => $order->id,
                    'updated_at' => now(),
                ]);
        }

        return $redemption;
    }
}
