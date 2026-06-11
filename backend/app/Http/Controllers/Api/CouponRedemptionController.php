<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lista los historiales de redención de un cupón específico.
 *
 * Requiere permiso coupons.read. Verifica que el cupón pertenezca a la empresa activa.
 * Paginado por offset; per_page máximo 100.
 */
class CouponRedemptionController extends Controller
{
    public function __construct(
        private readonly FeaturePermissionService $permissionService,
    ) {}

    public function index(Request $request, string $couponId): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'coupons', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $coupon = Coupon::forCompany($companyNit)->findOrFail($couponId);

        $perPage = min((int) $request->integer('per_page', 15), 100);

        $redemptions = $coupon->redemptions()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($redemptions);
    }
}
