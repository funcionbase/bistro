<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\StoreCouponRequest;
use App\Http\Requests\Coupon\UpdateCouponRequest;
use App\Http\Requests\Coupon\UpdateStatusRequest;
use App\Models\Coupon;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de cupones de descuento para una empresa.
 *
 * update() y destroy(): bloquean modificación si uses_count > 0 (condiciones son inmutables tras primer uso).
 * SoftDeletes: destroy() usa forceDelete() si el cupón no tiene usos; con usos solo se puede cambiar status.
 * Todos los cambios se registran en auditoría.
 */
class CouponController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly FeaturePermissionService $permissionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'coupons', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $coupons = Coupon::forCompany($companyNit)
            ->withCount('redemptions')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($coupons);
    }

    public function store(StoreCouponRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'coupons', 'create');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $branchId = (string) $request->attributes->get('active_branch_id');
        // Multi-sede: scope=branch (default) o scope=company. Si es company y
        // valid_in_branches viene NULL, aplica a TODAS las sedes presentes y futuras.
        $scope = $request->input('scope', 'branch');
        $validInBranches = $scope === 'company' ? $request->input('valid_in_branches') : null;

        $coupon = Coupon::create([
            'company_nit' => $companyNit,
            'branch_id' => $branchId,
            'scope' => $scope,
            'valid_in_branches' => $validInBranches,
            'code' => $request->code,
            'type' => $request->type,
            'value' => $request->value,
            'valid_from' => $request->valid_from,
            'valid_until' => $request->valid_until,
            'valid_days' => $request->input('valid_days'),
            'valid_hours_from' => $request->input('valid_hours_from'),
            'valid_hours_to' => $request->input('valid_hours_to'),
            'auto_apply' => $request->boolean('auto_apply', false),
            'max_uses' => $request->max_uses,
            'min_order_amount' => $request->min_order_amount ?? 0,
            'first_order_only' => $request->boolean('first_order_only', false),
            'is_active' => true,
            'status' => 'active',
            'created_by' => $jwtPayload['sub'] ?? null,
        ]);

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('coupon.created', $actor, $coupon, [
            'company_nit' => $companyNit,
            'code' => $coupon->code,
        ], $request);

        return response()->json(['data' => $coupon], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'coupons', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $coupon = Coupon::forCompany($companyNit)
            ->with(['redemptions' => fn ($q) => $q->latest('created_at')->limit(50)])
            ->withCount('redemptions')
            ->findOrFail($id);

        return response()->json(['data' => $coupon]);
    }

    public function update(UpdateCouponRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'coupons', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $coupon = Coupon::forCompany($companyNit)->findOrFail($id);

        if ($coupon->uses_count > 0) {
            return response()->json(['message' => 'No se puede editar un cupón que ya tiene redenciones.'], 409);
        }

        $before = $coupon->only(['type', 'value', 'valid_from', 'valid_until', 'valid_days', 'valid_hours_from', 'valid_hours_to', 'auto_apply', 'max_uses', 'min_order_amount', 'first_order_only', 'status']);

        $coupon->update($request->validated());

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('coupon.updated', $actor, $coupon, [
            'before' => $before,
            'after' => $coupon->refresh()->only(['type', 'value', 'valid_from', 'valid_until', 'valid_days', 'valid_hours_from', 'valid_hours_to', 'auto_apply', 'max_uses', 'min_order_amount', 'first_order_only', 'status']),
        ], $request);

        return response()->json(['data' => $coupon]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'coupons', 'delete');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $coupon = Coupon::forCompany($companyNit)->findOrFail($id);

        if ($coupon->uses_count > 0) {
            return response()->json(['message' => 'No se puede eliminar un cupón que ya tiene redenciones.'], 409);
        }

        $snapshot = ['company_nit' => $companyNit, 'code' => $coupon->code];

        $coupon->forceDelete();

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('coupon.deleted', $actor, $coupon, $snapshot, $request);

        return response()->json(null, 204);
    }

    public function status(UpdateStatusRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'coupons', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $coupon = Coupon::forCompany($companyNit)->findOrFail($id);
        $previousStatus = $coupon->status;
        $newStatus = $request->validated()['status'];

        $coupon->update([
            'status' => $newStatus,
            'is_active' => $newStatus === 'active',
        ]);

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('coupon.status_changed', $actor, $coupon, [
            'code' => $coupon->code,
            'from' => $previousStatus,
            'to' => $newStatus,
        ], $request);

        return response()->json(['data' => $coupon->refresh()]);
    }
}
