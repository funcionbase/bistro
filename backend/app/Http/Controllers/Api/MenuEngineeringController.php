<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Metrics\GetMenuEngineeringRequest;
use App\Services\MenuEngineeringService;
use App\Services\ReportsPermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * Expone la matriz de menu engineering (popularidad x margen unitario) para
 * `/company/metrics`. Requiere `reports.read`.
 */
class MenuEngineeringController extends Controller
{
    public function __construct(
        private readonly MenuEngineeringService $menuEngineeringService,
        private readonly ReportsPermissionService $permissionService,
    ) {}

    public function matrix(GetMenuEngineeringRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $validated = $request->validated();
        $period = $validated['period'] ?? 'month';
        $limit = (int) ($validated['limit'] ?? 200);
        [$dateFrom, $dateTo] = $this->parseDates($validated, $period);

        return response()->json($this->menuEngineeringService->matrix($companyNit, $period, $dateFrom, $dateTo, $limit));
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
