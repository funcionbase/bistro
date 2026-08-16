<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CrmService;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoints públicos de fidelización para el menú web.
 *
 * Sin autenticación: rate-limit estricto por IP y phone para evitar enumeración.
 * NO devuelve PII más allá del balance/tier del phone solicitado. Si la empresa
 * tiene el programa deshabilitado, responde 404 (no se debe revelar que el
 * teléfono existe en la BD).
 *
 * Si la empresa está bloqueada por mora, también respondemos 404 sin
 * revelar el motivo comercial al comensal. Misma política que el menú público.
 */
class PublicLoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyaltyService) {}

    /**
     * Consulta de saldo. POST (no GET) para no dejar phones en logs/access.log.
     */
    public function lookup(Request $request, string $nit): JsonResponse
    {
        $validated = $request->validate([
            'client_phone' => ['required', 'string', 'min:6', 'max:30'],
        ]);

        $this->assertCompanyOperational($nit);

        if (! $this->loyaltyService->isEnabledFor($nit)) {
            abort(404);
        }

        $phone = CrmService::normalizePhone($validated['client_phone']);
        if ($phone === '') {
            abort(422, 'Teléfono inválido.');
        }

        $account = $this->loyaltyService->findAccount($nit, $phone);
        $balance = $account ? (int) $account->balance : 0;
        $lifetime = $account ? (int) $account->lifetime_earned : 0;
        $progress = $this->loyaltyService->tierProgress($nit, $lifetime);

        return response()->json([
            'data' => [
                'balance' => $balance,
                'lifetime_earned' => $lifetime,
                'tier' => $progress['tier'],
                'tier_progress' => $progress,
                'rewards' => array_map(
                    fn (array $r, string $key) => [
                        'key' => $key,
                        'label' => $r['label'] ?? $key,
                        'points' => (int) $r['points'],
                        'discount_type' => $r['discount_type'],
                        'discount_value' => (float) $r['discount_value'],
                        'min_order_amount' => (float) ($r['min_order_amount'] ?? 0),
                        'redeemable' => $balance >= (int) $r['points'],
                    ],
                    $this->loyaltyService->rewardsFor($nit),
                    array_keys($this->loyaltyService->rewardsFor($nit)),
                ),
            ],
        ]);
    }

    /**
     * Canje público: el cliente identifica su phone y elige una recompensa.
     * Devuelve un coupon.code que la UI aplica automáticamente al carrito.
     */
    public function redeem(Request $request, string $nit): JsonResponse
    {
        $this->assertCompanyOperational($nit);

        if (! $this->loyaltyService->isEnabledFor($nit)) {
            abort(404);
        }

        $rewards = $this->loyaltyService->rewardsFor($nit);
        $validated = $request->validate([
            'client_phone' => ['required', 'string', 'min:6', 'max:30'],
            'reward_key' => ['required', 'string', Rule::in(array_keys($rewards))],
        ]);

        $result = $this->loyaltyService->redeem(
            companyNit: $nit,
            clientPhone: $validated['client_phone'],
            rewardKey: $validated['reward_key'],
            actor: null,
        );

        return response()->json([
            'data' => [
                'coupon_code' => $result['coupon']->code,
                'discount_type' => $result['coupon']->type,
                'discount_value' => (float) $result['coupon']->value,
                'min_order_amount' => (float) $result['coupon']->min_order_amount,
                'expires_at' => $result['coupon']->valid_until?->toIso8601String(),
                'balance' => (int) $result['account']->balance,
                'reward_label' => $rewards[$validated['reward_key']]['label'] ?? $validated['reward_key'],
            ],
        ], 201);
    }

    /**
     * Guard de empresa operativa. Si está bloqueada por mora,
     * abortamos 404 indistinguible de "loyalty deshabilitado" para que
     * el comensal no pueda deducir el motivo comercial.
     */
    private function assertCompanyOperational(string $nit): void
    {
        $company = Company::query()->where('nit', $nit)->first();

        if ($company === null || ! $company->canServePublic()) {
            abort(404);
        }
    }
}
