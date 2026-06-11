<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeliveryService;
use App\Services\FeaturePermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agrega métricas de entregas de la empresa activa por período.
 *
 * Requiere deliveries.read. Períodos: today (default), week, month.
 * Delega el cálculo a DeliveryService::getCompanyMetrics().
 */
class DeliveryMetricsController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveryService,
        private readonly FeaturePermissionService $permissionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'in:today,week,month'],
        ]);
        $period = $validated['period'] ?? 'today';

        [$from, $to] = $this->resolvePeriod($period);

        $metrics = $this->deliveryService->getCompanyMetrics($companyNit, $from, $to);

        return response()->json([
            'data' => $metrics,
            'period' => $period,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ]);
    }

    /** @return array{Carbon, Carbon} */
    private function resolvePeriod(string $period): array
    {
        return match ($period) {
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }
}
