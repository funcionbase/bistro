<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Billing\CompanyAlreadyHasActivePromoException;
use App\Exceptions\Billing\PromoCodeMaxCompaniesReachedException;
use App\Exceptions\Billing\PromoCodeNotApplicableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ApplyPromoCodeRequest;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\CompanyPromoCode;
use App\Services\PromoCodeService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints públicos y privados para promo codes y plan default.
 *
 * Públicos (sin auth, throttle):
 *  - `GET  /api/v1/billing/plans/default` — plan vigente para enrollment.
 *  - `GET  /api/v1/promo-codes/{code}/preview` — preview pre-login para `?promo=` URL.
 *
 * Privados (con auth + company.access + permission):
 *  - `GET    /api/v1/company/billing/promo-code` — promo activo de la empresa.
 *  - `POST   /api/v1/company/billing/promo-code/preview` — preview self-service.
 *  - `POST   /api/v1/company/billing/promo-code` — aplicar self-service.
 *  - `DELETE /api/v1/company/billing/promo-code` — cancelar activo.
 *
 * Aplicación self-service:
 *  - Permiso: owner + admin automático (`role.is_system=true` y slug ∈
 *    {owner, admin}). Sin permiso asignable — decisión de diseño.
 *  - `starts_at` = primer día del próximo mes Bogota (deferido por trial activo).
 */
class PromoCodeController extends Controller
{
    public function __construct(private readonly PromoCodeService $promoCodeService) {}

    /**
     * GET /api/v1/billing/plans/default — público, throttle.
     * Devuelve el plan default vigente para mostrar en enrollment.
     */
    public function defaultPlan(): JsonResponse
    {
        $plan = BillingPlan::default();

        if ($plan === null) {
            return response()->json([
                'error_code' => 'DEFAULT_PLAN_NOT_CONFIGURED',
                'message' => 'No hay un plan default configurado.',
            ], 503);
        }

        return response()->json([
            'data' => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'description' => $plan->description,
                'price' => (float) $plan->price,
                'currency' => $plan->currency,
                'billing_cycle' => $plan->billing_cycle,
                'price_includes_tax' => $plan->price_includes_tax,
                'tax_regime' => $plan->tax_regime,
                'tax_rate' => (float) $plan->tax_rate,
                'features' => $plan->features ?? [],
            ],
        ]);
    }

    /**
     * GET /api/v1/promo-codes/{code}/preview — público, throttle.
     * Valida un código y devuelve preview de descuento + precios computados.
     */
    public function previewPublic(string $code): JsonResponse
    {
        try {
            $promo = $this->promoCodeService->validateBySlug($code);
        } catch (PromoCodeNotApplicableException|PromoCodeMaxCompaniesReachedException $e) {
            return $this->errorResponse($e);
        }

        $defaultPlan = BillingPlan::default();
        $planPrice = $defaultPlan !== null ? (float) $defaultPlan->price : 0.0;
        $discountAmount = Money::applyPercent($planPrice, $promo->discount_percent);
        $finalPrice = Money::round($planPrice - $discountAmount);

        return response()->json([
            'data' => [
                'code' => $promo->code,
                'name' => $promo->name,
                'description' => $promo->description,
                'discount_percent' => $promo->discount_percent,
                'months_duration' => $promo->months_duration,
                'plan_default_price' => $planPrice,
                'discount_amount' => $discountAmount,
                'discounted_price' => $finalPrice,
                'monthly_savings' => $discountAmount,
            ],
        ]);
    }

    /**
     * GET /api/v1/company/billing/promo-code — privado.
     * Devuelve el promo activo de la empresa + invoices afectadas.
     */
    public function showActive(Request $request): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');

        $active = CompanyPromoCode::query()
            ->with(['promoCode'])
            ->where('company_nit', $companyNit)
            ->where('status', 'active')
            ->first();

        if ($active === null) {
            return response()->json(['data' => null]);
        }

        $affectedInvoices = $active->invoices()
            ->select(['id', 'period_from', 'period_to', 'amount', 'discount_amount', 'status'])
            ->orderBy('period_from', 'desc')
            ->get();

        return response()->json([
            'data' => [
                'id' => $active->id,
                'code' => $active->promoCode?->code,
                'name' => $active->promoCode?->name,
                'discount_percent' => $active->discount_percent,
                'months_duration' => $active->months_duration,
                'starts_at' => $active->starts_at?->toIso8601String(),
                'ends_at' => $active->ends_at?->toIso8601String(),
                'status' => $active->status,
                'applied_via' => $active->applied_via,
                'applied_at' => $active->created_at?->toIso8601String(),
                'invoices' => $affectedInvoices,
            ],
        ]);
    }

    /**
     * POST /api/v1/company/billing/promo-code/preview — privado.
     * Preview personalizado para empresa autenticada antes de confirmar
     * (`POST /promo-code`).
     */
    public function previewForCompany(ApplyPromoCodeRequest $request): JsonResponse
    {
        $companyNit = (string) $request->attributes->get('active_company_nit');
        $code = (string) $request->validated('code');

        try {
            $promo = $this->promoCodeService->validateBySlug($code);
        } catch (PromoCodeNotApplicableException|PromoCodeMaxCompaniesReachedException $e) {
            return $this->errorResponse($e);
        }

        $existing = CompanyPromoCode::query()
            ->where('company_nit', $companyNit)
            ->where('status', 'active')
            ->first();

        if ($existing !== null) {
            return response()->json([
                'error_code' => 'COMPANY_ALREADY_HAS_ACTIVE_PROMO',
                'message' => 'La empresa ya tiene un promo activo. Cancélalo antes de inscribir otro.',
            ], 422);
        }

        $company = Company::query()->where('nit', $companyNit)->firstOrFail();
        $defaultPlan = BillingPlan::default();
        $planPrice = $defaultPlan !== null ? (float) $defaultPlan->price : 0.0;
        $discountAmount = Money::applyPercent($planPrice, $promo->discount_percent);
        $finalPrice = Money::round($planPrice - $discountAmount);

        // Simulación de starts_at / ends_at sin persistir.
        $now = now('America/Bogota');
        $simulatedStartsAt = $now->copy()->addMonthNoOverflow()->startOfMonth();
        $paidStart = $company->paid_billing_starts_at;
        if ($paidStart !== null && $paidStart->gt($simulatedStartsAt)) {
            $simulatedStartsAt = $paidStart->copy();
        }
        $simulatedEndsAt = $simulatedStartsAt->copy()->addMonths($promo->months_duration);

        return response()->json([
            'data' => [
                'code' => $promo->code,
                'name' => $promo->name,
                'description' => $promo->description,
                'discount_percent' => $promo->discount_percent,
                'months_duration' => $promo->months_duration,
                'current_plan_price' => $planPrice,
                'discount_amount' => $discountAmount,
                'discounted_price' => $finalPrice,
                'monthly_savings' => $discountAmount,
                'starts_at_preview' => $simulatedStartsAt->toIso8601String(),
                'ends_at_preview' => $simulatedEndsAt->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/company/billing/promo-code — privado.
     * Aplica el promo a la empresa autenticada (self-service).
     *
     * Permiso: owner + admin estricto. Sin permiso
     * asignable — `role.is_system=true` y nombre canonical.
     */
    public function applySelfService(ApplyPromoCodeRequest $request): JsonResponse
    {
        $this->assertOwnerOrAdmin($request);

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $code = (string) $request->validated('code');
        $company = Company::query()->where('nit', $companyNit)->firstOrFail();

        try {
            $promo = $this->promoCodeService->validateBySlug($code);
            $application = $this->promoCodeService->applyToCompany(
                $company,
                $promo,
                appliedVia: 'self_service',
                appliedByUserId: $request->user()?->id,
            );
        } catch (PromoCodeNotApplicableException|PromoCodeMaxCompaniesReachedException|CompanyAlreadyHasActivePromoException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'data' => [
                'id' => $application->id,
                'code' => $promo->code,
                'discount_percent' => $application->discount_percent,
                'months_duration' => $application->months_duration,
                'starts_at' => $application->starts_at?->toIso8601String(),
                'ends_at' => $application->ends_at?->toIso8601String(),
                'status' => $application->status,
            ],
        ], 201);
    }

    /**
     * DELETE /api/v1/company/billing/promo-code — privado.
     * Cancela el promo activo de la empresa autenticada.
     *
     * Permiso: owner + admin estricto.
     */
    public function cancelSelfService(Request $request): JsonResponse
    {
        $this->assertOwnerOrAdmin($request);

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $company = Company::query()->where('nit', $companyNit)->firstOrFail();

        $cancelled = $this->promoCodeService->cancelForCompany(
            $company,
            cancelledByUserId: $request->user()?->id,
        );

        if ($cancelled === null) {
            return response()->json([
                'error_code' => 'NO_ACTIVE_PROMO',
                'message' => 'No hay un promo activo para cancelar.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $cancelled->id,
                'status' => $cancelled->status,
                'cancelled_at' => $cancelled->cancelled_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Verifica que el JWT activo sea owner o admin (`is_system=true` y nombre
     * matchea `config('roles.role_names.owner')` o `.admin`). 403 si no.
     */
    private function assertOwnerOrAdmin(Request $request): void
    {
        $jwtPayload = (array) $request->attributes->get('jwt_payload');
        $role = $jwtPayload['role'] ?? null;

        if (! is_array($role) || ($role['is_system'] ?? false) !== true) {
            abort(403, 'Solo owner o admin pueden gestionar promo codes.');
        }

        $ownerName = (string) config('roles.role_names.owner');
        $adminName = (string) config('roles.role_names.admin');
        $roleName = (string) ($role['name'] ?? '');

        if (! in_array($roleName, [$ownerName, $adminName], true)) {
            abort(403, 'Solo owner o admin pueden gestionar promo codes.');
        }
    }

    private function errorResponse(\RuntimeException $e): JsonResponse
    {
        $code = property_exists($e, 'errorCode') ? $e->errorCode : 'PROMO_CODE_ERROR';

        return response()->json([
            'error_code' => $code,
            'message' => $e->getMessage(),
        ], 422);
    }
}
