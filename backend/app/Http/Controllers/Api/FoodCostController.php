<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Metrics\GetFoodCostHistoryRequest;
use App\Http\Requests\Metrics\GetFoodCostSummaryRequest;
use App\Services\FoodCostMetricsService;
use App\Services\ReportsPermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * Expone food cost (costo de alimentos) en tiempo real para el dashboard.
 *
 * Endpoints requieren `reports.read`. summary() dispara `ensureTodaySnapshot`
 * antes de servir para garantizar histórico aún sin scheduler corriendo (lazy
 * backfill protegido por lock + throttle 6h, ver FoodCostMetricsService).
 */
class FoodCostController extends Controller
{
    public function __construct(
        private readonly FoodCostMetricsService $foodCostService,
        private readonly ReportsPermissionService $permissionService,
    ) {}

    public function summary(GetFoodCostSummaryRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        // Filtro de sede (#costeo-multibodega): active_branch_id = sede activa;
        // null = consolidado (?branch=all) vía branch.consolidate.
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';
        $limit = (int) ($request->validated()['limit'] ?? 100);
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        // Lazy backfill del snapshot diario (idempotente, throttle 6h).
        $this->foodCostService->ensureTodaySnapshot($companyNit);

        return response()->json($this->foodCostService->summary($companyNit, $period, $dateFrom, $dateTo, $limit, $branchId));
    }

    public function itemHistory(GetFoodCostHistoryRequest $request, string $menuItemId): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'month';
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        return response()->json($this->foodCostService->itemHistory($companyNit, $menuItemId, $period, $dateFrom, $dateTo, $branchId));
    }

    /** @param array<string, mixed> $validated
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function parseDates(array $validated, string $period): array
    {
        if ($period !== 'custom') {
            return [null, null];
        }

        return [
            Carbon::createFromFormat('Y-m-d', $validated['date_from']),
            Carbon::createFromFormat('Y-m-d', $validated['date_to']),
        ];
    }
}
