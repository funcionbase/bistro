<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoyaltyAccountResource;
use App\Http\Resources\LoyaltyMovementResource;
use App\Models\LoyaltyAccount;
use App\Rules\SafePlainText;
use App\Services\CrmService;
use App\Services\FeaturePermissionService;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoints staff de fidelización (#122).
 *
 * Permisos: loyalty.read (lectura) / loyalty.update (ajuste manual y canje
 * a nombre del cliente). Cross-sede: las cuentas viven por (company_nit,
 * client_phone) sin importar la sede activa del actor.
 */
class LoyaltyController extends Controller
{
    use ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly LoyaltyService $loyaltyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'loyalty', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'tier' => ['nullable', 'string', Rule::in(array_keys($this->loyaltyService->configFor($companyNit)['tiers']))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LoyaltyAccount::forCompany($companyNit);

        if (! empty($validated['search'])) {
            $needle = CrmService::normalizePhone($validated['search']);
            if ($needle !== '') {
                $query->where('client_phone', 'like', "%{$needle}%");
            }
        }

        if (! empty($validated['tier'])) {
            $query->where('tier', $validated['tier']);
        }

        $paginator = $query->orderByDesc('balance')
            ->paginate(perPage: (int) ($validated['per_page'] ?? 25), page: (int) ($validated['page'] ?? 1));

        return response()->json([
            'data' => LoyaltyAccountResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'tiers' => array_keys($this->loyaltyService->configFor($companyNit)['tiers']),
            ],
        ]);
    }

    public function show(Request $request, string $phone): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'loyalty', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $normalized = CrmService::normalizePhone($phone);

        $account = $this->loyaltyService->findAccount($companyNit, $normalized);

        if ($account === null) {
            // No abortamos en 404: si el cliente existe en CRM pero aún no tiene
            // cuenta de puntos, devolvemos un placeholder con balance 0 para que
            // la UI muestre tarjeta vacía en lugar de error.
            return response()->json([
                'data' => [
                    'id' => null,
                    'company_nit' => $companyNit,
                    'client_phone' => $normalized,
                    'balance' => 0,
                    'lifetime_earned' => 0,
                    'tier' => 'bronze',
                    'tier_progress' => $this->loyaltyService->tierProgress($companyNit, 0),
                    'last_activity_at' => null,
                    'created_at' => null,
                    'movements' => [],
                    'rewards' => $this->loyaltyService->rewardsFor($companyNit),
                    'config' => ['enabled' => $this->loyaltyService->isEnabledFor($companyNit)],
                ],
            ]);
        }

        $account->load(['movements' => function ($q) {
            $q->orderByDesc('created_at')->limit(50);
        }, 'movements.actor:id,name']);

        return response()->json([
            'data' => array_merge(
                (new LoyaltyAccountResource($account))->resolve($request),
                [
                    'rewards' => $this->loyaltyService->rewardsFor($companyNit),
                    'config' => ['enabled' => $this->loyaltyService->isEnabledFor($companyNit)],
                ],
            ),
        ]);
    }

    public function adjust(Request $request, string $phone): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'loyalty', 'update');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $actor = $this->actingUserOrFail($request);
        $normalized = CrmService::normalizePhone($phone);

        $validated = $request->validate([
            'points' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'min:3', new SafePlainText(maxBytes: 255, allowWhitespace: true)],
        ]);

        $movement = $this->loyaltyService->adjust(
            companyNit: $companyNit,
            clientPhone: $normalized,
            points: (int) $validated['points'],
            reason: $validated['reason'],
            actor: $actor,
        );

        return response()->json(['data' => (new LoyaltyMovementResource($movement))->resolve($request)], 201);
    }

    public function redeem(Request $request, string $phone): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'loyalty', 'update');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $actor = $this->actingUserOrFail($request);
        $normalized = CrmService::normalizePhone($phone);

        $rewards = $this->loyaltyService->rewardsFor($companyNit);
        $validated = $request->validate([
            'reward_key' => ['required', 'string', Rule::in(array_keys($rewards))],
        ]);

        $result = $this->loyaltyService->redeem(
            companyNit: $companyNit,
            clientPhone: $normalized,
            rewardKey: $validated['reward_key'],
            actor: $actor,
        );

        return response()->json([
            'data' => [
                'coupon' => [
                    'id' => $result['coupon']->id,
                    'code' => $result['coupon']->code,
                    'value' => (float) $result['coupon']->value,
                    'type' => $result['coupon']->type,
                    'valid_until' => $result['coupon']->valid_until?->toIso8601String(),
                ],
                'redemption' => [
                    'id' => $result['redemption']->id,
                    'reward_key' => $result['redemption']->reward_key,
                    'points' => (int) $result['redemption']->points,
                    'status' => $result['redemption']->status,
                    'expires_at' => $result['redemption']->expires_at?->toIso8601String(),
                ],
                'balance' => (int) $result['account']->balance,
            ],
        ], 201);
    }
}
