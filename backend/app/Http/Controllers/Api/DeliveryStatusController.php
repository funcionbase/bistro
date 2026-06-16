<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\ReassignDeliveryRequest;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consulta repartidores disponibles y reasigna entregas pendientes.
 *
 * getAvailableDeliverers(): requiere deliveries.read; verifica que la orden pertenezca a la empresa.
 * reassign(): requiere deliveries.update; solo entregas en status 'pending' pueden reasignarse.
 * La reasignación cancela la entrega actual y crea una nueva via DeliveryService::reassignDeliverer().
 * No permite asignar al mismo repartidor actual (retorna 422).
 */
class DeliveryStatusController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveryService,
        private readonly FeaturePermissionService $permissionService,
    ) {}

    public function getAvailableDeliverers(Request $request, string $orderId): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        Order::forCompany($companyNit)->findOrFail($orderId);

        $company = Company::where('nit', $companyNit)->firstOrFail();

        // Filtra por acceso a la sede activa (branch_users), bypass is_system.
        $activeBranchId = $request->attributes->get('active_branch_id');
        $deliverers = $this->deliveryService->getAvailableDeliverers($company, $activeBranchId);

        return response()->json(['data' => $deliverers]);
    }

    public function reassign(ReassignDeliveryRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $delivery = Delivery::forCompany($companyNit)->with('order')->findOrFail($id);

        if (! $delivery->isPending()) {
            return response()->json(['message' => 'Solo se pueden reasignar entregas pendientes.'], 422);
        }

        if ($delivery->order?->status === 'completed') {
            return response()->json(['message' => 'Orden completada, no editable.'], 409);
        }

        $newDeliverer = User::findOrFail($request->user_id);

        if ($newDeliverer->id === $delivery->user_id) {
            return response()->json(['message' => 'El nuevo repartidor debe ser diferente al actual.'], 422);
        }

        $reassignedBy = User::findOrFail($jwtPayload['sub']);

        $newDelivery = $this->deliveryService->reassignDeliverer(
            $delivery,
            $newDeliverer,
            $reassignedBy,
            $request->input('reason'),
        );

        return response()->json(['data' => $newDelivery], 201);
    }
}
