<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CrmService;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoints de fidelización para el bot externo.
 *
 * El bot (n8n / WhatsApp) consume estos para responder a comandos como
 * /puntos o /canjear desde el chat. company_nit viene del JWT (bot.jwt),
 * nunca del body — impide cross-company.
 */
class ExternalLoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyaltyService) {}

    public function lookup(Request $request): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('bot_company_nit');

        $validated = $request->validate([
            'client_phone' => ['required', 'string', 'min:6', 'max:30'],
        ]);

        if (! $this->loyaltyService->isEnabledFor($companyNit)) {
            return response()->json(['data' => ['enabled' => false]]);
        }

        $phone = CrmService::normalizePhone($validated['client_phone']);
        $account = $this->loyaltyService->findAccount($companyNit, $phone);
        $lifetime = $account ? (int) $account->lifetime_earned : 0;
        $progress = $this->loyaltyService->tierProgress($companyNit, $lifetime);

        return response()->json([
            'data' => [
                'enabled' => true,
                'balance' => $account ? (int) $account->balance : 0,
                'lifetime_earned' => $lifetime,
                'tier' => $progress['tier'],
                'tier_progress' => $progress,
                'rewards' => array_values(array_map(
                    fn (array $r, string $k) => [
                        'key' => $k,
                        'label' => $r['label'] ?? $k,
                        'points' => (int) $r['points'],
                        'discount_value' => (float) $r['discount_value'],
                        'min_order_amount' => (float) ($r['min_order_amount'] ?? 0),
                    ],
                    $this->loyaltyService->rewardsFor($companyNit),
                    array_keys($this->loyaltyService->rewardsFor($companyNit)),
                )),
            ],
        ]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('bot_company_nit');

        if (! $this->loyaltyService->isEnabledFor($companyNit)) {
            abort(404);
        }

        $rewards = $this->loyaltyService->rewardsFor($companyNit);
        $validated = $request->validate([
            'client_phone' => ['required', 'string', 'min:6', 'max:30'],
            'reward_key' => ['required', 'string', Rule::in(array_keys($rewards))],
        ]);

        $result = $this->loyaltyService->redeem(
            companyNit: $companyNit,
            clientPhone: $validated['client_phone'],
            rewardKey: $validated['reward_key'],
            actor: null,
        );

        return response()->json([
            'data' => [
                'coupon_code' => $result['coupon']->code,
                'discount_value' => (float) $result['coupon']->value,
                'min_order_amount' => (float) $result['coupon']->min_order_amount,
                'expires_at' => $result['coupon']->valid_until?->toIso8601String(),
                'balance' => (int) $result['account']->balance,
                'reward_label' => $rewards[$validated['reward_key']]['label'] ?? $validated['reward_key'],
            ],
        ], 201);
    }
}
