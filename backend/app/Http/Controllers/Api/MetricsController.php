<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Metrics\GetAbandonmentRateRequest;
use App\Http\Requests\Metrics\GetActiveOrdersRequest;
use App\Http\Requests\Metrics\GetActivityHeatmapRequest;
use App\Http\Requests\Metrics\GetDishMarginRequest;
use App\Http\Requests\Metrics\GetKpisRequest;
use App\Http\Requests\Metrics\GetMenuScansRequest;
use App\Http\Requests\Metrics\GetMetricsAbandmentRequest;
use App\Http\Requests\Metrics\GetMetricsHeatmapRequest;
use App\Http\Requests\Metrics\GetMetricsSummaryRequest;
use App\Http\Requests\Metrics\GetMetricsTopItemsRequest;
use App\Http\Requests\Metrics\GetMetricsWeeklyHeatmapRequest;
use App\Http\Requests\Metrics\GetSmsCountsRequest;
use App\Http\Requests\Metrics\GetTopDishesRequest;
use App\Models\OfflineSyncEvent;
use App\Services\MetricsService;
use App\Services\ReportsPermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expone KPIs, heatmaps y rankings para el dashboard del restaurante.
 *
 * Todos los endpoints requieren permiso reports.read (verificado via ReportsPermissionService).
 * Los períodos válidos son: today, week, month y custom (custom requiere date_from y date_to).
 * Los resultados se cachean por MetricsService; el caché invalida automáticamente al vencer el TTL.
 *
 * @env METRICS_CACHE_TTL — TTL general de caché de métricas
 */
class MetricsController extends Controller
{
    public function __construct(
        private readonly MetricsService $metricsService,
        private readonly ReportsPermissionService $permissionService,
    ) {}

    public function kpisToday(GetKpisRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');

        return response()->json($this->metricsService->getKpisForToday($companyNit, $branchId));
    }

    public function activeOrders(GetActiveOrdersRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');

        return response()->json($this->metricsService->getActiveOrders($companyNit, $branchId));
    }

    public function topDishes(GetTopDishesRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';
        $limit = (int) ($request->validated()['limit'] ?? config('metrics.top_dishes_limit', 10));

        return response()->json($this->metricsService->getTopDishes($companyNit, $period, $limit, $branchId));
    }

    public function abandonmentRate(GetAbandonmentRateRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';

        return response()->json($this->metricsService->getAbandonmentRate($companyNit, $period, $branchId));
    }

    public function activityHeatmap(GetActivityHeatmapRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';

        return response()->json($this->metricsService->getActivityHeatmap($companyNit, $period, $branchId));
    }

    /**
     * Métricas de operación offline (#140).
     *
     * Agrega `offline_sync_events` para los últimos 30 días por defecto y
     * devuelve totales por tipo de evento (órdenes sincronizadas, cobros
     * sincronizados, fallos) más una serie diaria para gráfica.
     *
     * Período: query param `period` ∈ today | week | month | custom (con
     * date_from/date_to).
     */
    public function offlineOperation(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');

        $period = $request->query('period', 'month');
        $tz = config('orders.timezone', 'America/Bogota');
        $now = Carbon::now($tz);

        [$from, $to] = match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'custom' => [
                Carbon::parse((string) $request->query('date_from', $now->copy()->subDays(30)->toDateString()), $tz)->startOfDay(),
                Carbon::parse((string) $request->query('date_to', $now->toDateString()), $tz)->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };

        // occurred_at se persiste en wall-clock del APP_TIMEZONE (Bogotá);
        // comparar contra ->utc() corría la ventana +5h. Además ->utc() sin
        // copy() mutaba $from/$to y el payload `period` salía en UTC.
        $appTz = config('app.timezone');
        $fromDb = $from->copy()->setTimezone($appTz);
        $toDb = $to->copy()->setTimezone($appTz);

        $rows = OfflineSyncEvent::forCompany($companyNit)
            ->whereBetween('occurred_at', [$fromDb, $toDb])
            ->selectRaw('event_type,
                SUM("count") AS total_count,
                SUM(total_amount) AS total_amount')
            ->groupBy('event_type')
            ->get()
            ->keyBy('event_type');

        $totals = [
            'orders_synced' => (int) ($rows['order_synced']->total_count ?? 0),
            'receipts_synced' => (int) ($rows['receipt_synced']->total_count ?? 0),
            'failed' => (int) ($rows['sync_failed']->total_count ?? 0),
            'amount_synced' => (float) ($rows['receipt_synced']->total_amount ?? 0),
        ];

        $daily = OfflineSyncEvent::forCompany($companyNit)
            ->whereBetween('occurred_at', [$fromDb, $toDb])
            ->whereIn('event_type', ['order_synced', 'receipt_synced'])
            ->selectRaw('DATE(occurred_at) AS day,
                event_type,
                SUM("count") AS total_count,
                SUM(total_amount) AS total_amount')
            ->groupBy('day', 'event_type')
            ->orderBy('day')
            ->get();

        return response()->json([
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'label' => $period],
            'totals' => $totals,
            'daily' => $daily,
        ]);
    }

    // ── Part 2: dynamic-period endpoints ──────────────────────────────────────

    public function summary(GetMetricsSummaryRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        return response()->json($this->metricsService->getSummary($companyNit, $period, $dateFrom, $dateTo, $branchId));
    }

    public function orderHeatmap(GetMetricsHeatmapRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        return response()->json($this->metricsService->getOrderHeatmap($companyNit, $period, $dateFrom, $dateTo, $branchId));
    }

    public function topItems(GetMetricsTopItemsRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';
        $limit = (int) ($request->validated()['limit'] ?? config('metrics.top_items_limit', 10));
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        return response()->json($this->metricsService->getTopItems($companyNit, $period, $dateFrom, $dateTo, $limit, $branchId));
    }

    public function weeklyHeatmap(GetMetricsWeeklyHeatmapRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'week';
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        return response()->json($this->metricsService->getWeeklyHeatmap($companyNit, $period, $dateFrom, $dateTo, $branchId));
    }

    public function dishMargin(GetDishMarginRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';
        $limit = (int) ($request->validated()['limit'] ?? 50);
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        return response()->json($this->metricsService->getDishMargin($companyNit, $period, $dateFrom, $dateTo, $limit, $branchId));
    }

    public function cartAbandonment(GetMetricsAbandmentRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        return response()->json($this->metricsService->getCartAbandonment($companyNit, $period, $dateFrom, $dateTo, $branchId));
    }

    /**
     * SMS enviados al cliente por cambios de estado de orden (#275, Fase 3):
     * total de empresa + desglose por sede en el período. `active_branch_id`
     * (inyectado por branch.access; null en consolidado vía branch.consolidate)
     * decide si se filtra a una sede o se agregan todas.
     */
    public function smsCounts(GetSmsCountsRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        return response()->json($this->metricsService->getSmsCounts($companyNit, $branchId, $period, $dateFrom, $dateTo));
    }

    /**
     * Escaneos del menú QR (#294): total, sesiones únicas, serie diaria y
     * desgloses por mesa/sede leyendo menu_scan_daily_rollup (+ día en curso
     * desde menu_scan_events). `active_branch_id` decide sede vs consolidado.
     */
    public function menuScans(GetMenuScansRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'read');
        $companyNit = $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');
        $period = $request->validated()['period'] ?? 'today';
        [$dateFrom, $dateTo] = $this->parseDates($request->validated(), $period);

        return response()->json($this->metricsService->getMenuScans($companyNit, $branchId, $period, $dateFrom, $dateTo));
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
