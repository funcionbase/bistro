<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CartSession;
use App\Models\Order;
use App\Models\OrderSmsNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calcula y cachea KPIs, heatmaps y rankings para el dashboard del restaurante.
 *
 * Todos los resultados se cachean con Cache::remember() o Cache::flexible() (sirve datos obsoletos
 * mientras recalcula en background). Los TTLs se configuran por tipo de dato en config/metrics.php.
 * Patrón de cache key: metrics:{companyNit}:{tipo}:{período}[:hash_custom].
 * Períodos válidos: today, week, month, custom (este último requiere date_from y date_to en Y-m-d).
 *
 * @env METRICS_CACHE_TTL — TTL genérico de caché (config metrics.cache_ttl, default: 60s)
 */
class MetricsService
{
    private string $timezone;

    private int $cacheTtl;

    private int $activeOrdersTtl;

    private int $summaryTtl;

    private int $chartTtl;

    private int $heatmapTtl;

    private int $metricsTtl;

    private bool $cacheEnabled;

    public function __construct()
    {
        $this->timezone = config('metrics.timezone', 'UTC');
        $this->cacheTtl = (int) config('metrics.cache_ttl', 60);
        $this->activeOrdersTtl = (int) config('metrics.active_orders_ttl', 30);
        $this->summaryTtl = (int) config('metrics.dashboard_summary_cache_ttl', 60);
        $this->chartTtl = (int) config('metrics.dashboard_chart_cache_ttl', 300);
        $this->heatmapTtl = (int) config('metrics.dashboard_heatmap_cache_ttl', 600);
        $this->metricsTtl = (int) config('metrics.dashboard_metrics_cache_ttl', 300);
        $this->cacheEnabled = (bool) config('metrics.dashboard_cache_enabled', true);
    }

    /**
     * (#costeo-multibodega / multisede #117) Todos los métodos aceptan
     * `$branchId`: null = consolidado (todas las sedes), uuid = una sede. Las
     * queries Eloquent (`Order::forCompany`/`CartSession::forCompany`) ya filtran
     * por la sede activa vía `BranchScope`; las de SQL crudo lo hacen a mano con
     * `AND branch_id = ?`. En AMBOS casos la sede entra en la cache key para no
     * servir datos de una sede a otra (polución de caché cross-sede).
     *
     * @return array<string, mixed>
     */
    public function getKpisForToday(string $companyNit, ?string $branchId = null): array
    {
        $key = "metrics:kpis:today:{$companyNit}:b:".($branchId ?? 'all');

        $cached = Cache::flexible($key, [$this->cacheTtl, $this->cacheTtl * 10], function () use ($companyNit) {
            $start = Carbon::now($this->timezone)->startOfDay();
            $end = Carbon::now($this->timezone)->endOfDay();
            $revenueStatuses = config('orders.revenue');

            $totalOrders = Order::forCompany($companyNit)->inPeriod($start, $end)->count();

            $revenueRow = Order::forCompany($companyNit)
                ->inPeriod($start, $end)
                ->whereIn('status', $revenueStatuses)
                ->selectRaw('COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS cnt')
                ->first();

            $totalRevenue = (float) ($revenueRow->revenue ?? 0);
            $revenueCount = (int) ($revenueRow->cnt ?? 0);

            $completedOrders = Order::forCompany($companyNit)->inPeriod($start, $end)->completed()->count();
            $cancelledOrders = Order::forCompany($companyNit)->inPeriod($start, $end)->cancelled()->count();
            $abandonedOrders = Order::forCompany($companyNit)->inPeriod($start, $end)->abandoned()->count();

            $activeBreakdown = $this->buildActiveBreakdown($companyNit);

            return [
                'date' => Carbon::now($this->timezone)->toDateString(),
                'total_orders' => $totalOrders,
                'total_revenue' => $totalRevenue,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'abandoned_orders' => $abandonedOrders,
                'average_ticket' => $revenueCount > 0 ? round($totalRevenue / $revenueCount, 2) : 0,
                'active_orders' => array_sum($activeBreakdown),
                'active_orders_breakdown' => $activeBreakdown,
            ];
        });

        return [
            'data' => $cached,
            'cached_at' => now()->toIso8601String(),
            'cache_ttl' => $this->cacheTtl,
        ];
    }

    /** @return array<string, mixed> */
    public function getActiveOrders(string $companyNit, ?string $branchId = null): array
    {
        $key = "metrics:orders:active:{$companyNit}:b:".($branchId ?? 'all');

        $data = Cache::flexible($key, [$this->activeOrdersTtl, $this->activeOrdersTtl * 5], function () use ($companyNit) {
            return $this->buildActiveBreakdown($companyNit);
        });

        return [
            'data' => $data,
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function getTopDishes(string $companyNit, string $period = 'today', int $limit = 10, ?string $branchId = null): array
    {
        $key = "metrics:dishes:ranking:{$companyNit}:{$period}:{$limit}:b:".($branchId ?? 'all');

        $data = Cache::flexible($key, [$this->cacheTtl, $this->cacheTtl * 10], function () use ($companyNit, $period, $limit, $branchId) {
            [$start, $end] = $this->resolvePeriod($period);
            $revenueStatuses = config('orders.revenue');
            $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

            $rows = DB::select(
                "SELECT
                    item->>'dish_id' AS dish_id,
                    item->>'name'    AS dish_name,
                    SUM(CAST(NULLIF(item->>'quantity','') AS INTEGER))                   AS times_ordered,
                    SUM(CAST(NULLIF(item->>'price','') AS NUMERIC)
                        * CAST(NULLIF(item->>'quantity','') AS INTEGER))                 AS revenue
                FROM orders,
                    json_array_elements(items) AS item
                WHERE company_nit = ?
                  AND ordered_at  BETWEEN ? AND ?
                  AND status      = ANY(?)
                  {$branchFilter}
                GROUP BY item->>'dish_id', item->>'name'
                ORDER BY times_ordered DESC
                LIMIT ?",
                $branchId !== null
                    ? [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}', $branchId, $limit]
                    : [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}', $limit]
            );

            $totalOrdered = collect($rows)->sum('times_ordered');

            $topDishes = collect($rows)->map(fn ($row) => [
                'dish_id' => $row->dish_id,
                'dish_name' => $row->dish_name,
                'times_ordered' => (int) $row->times_ordered,
                'revenue' => (float) $row->revenue,
                'percentage_of_total' => $totalOrdered > 0
                    ? round(($row->times_ordered / $totalOrdered) * 100, 1)
                    : 0,
            ])->values()->all();

            return [
                'period' => $period,
                'top_dishes' => $topDishes,
                'total_unique_dishes' => count($rows),
            ];
        });

        return ['data' => $data, 'cached' => true];
    }

    /** @return array<string, mixed> */
    public function getAbandonmentRate(string $companyNit, string $period = 'today', ?string $branchId = null): array
    {
        $key = "metrics:cart:abandonment:{$companyNit}:{$period}:b:".($branchId ?? 'all');

        $data = Cache::flexible($key, [$this->cacheTtl, $this->cacheTtl * 10], function () use ($companyNit, $period) {
            [$start, $end] = $this->resolvePeriod($period);

            $total = CartSession::forCompany($companyNit)->inPeriod($start, $end)->count();
            $abandoned = CartSession::forCompany($companyNit)->inPeriod($start, $end)->abandoned()->count();
            $active = $total - $abandoned;

            $revenueStatuses = config('orders.revenue');
            $revenueRow = Order::forCompany($companyNit)
                ->inPeriod($start, $end)
                ->whereIn('status', $revenueStatuses)
                ->selectRaw('COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS cnt')
                ->first();

            $avgTicket = (int) ($revenueRow->cnt ?? 0) > 0
                ? ((float) $revenueRow->revenue / (int) $revenueRow->cnt)
                : 0;

            return [
                'period' => $period,
                'total_cart_sessions' => $total,
                'active_sessions' => $active,
                'abandoned_sessions' => $abandoned,
                'abandonment_rate' => $total > 0 ? round(($abandoned / $total) * 100, 2) : 0,
                'estimated_lost_revenue' => round($abandoned * $avgTicket, 2),
            ];
        });

        return [
            'data' => $data,
            'calculation_method' => 'cart_sessions created vs abandoned in period',
        ];
    }

    /** @return array<string, mixed> */
    public function getActivityHeatmap(string $companyNit, string $period = 'today', ?string $branchId = null): array
    {
        $key = "metrics:activity:heatmap:{$companyNit}:{$period}:b:".($branchId ?? 'all');

        $data = Cache::flexible($key, [$this->cacheTtl, $this->cacheTtl * 10], function () use ($companyNit, $period, $branchId) {
            [$start, $end] = $this->resolvePeriod($period);
            $revenueStatuses = config('orders.revenue');
            $tz = $this->timezone;
            $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

            // ordered_at se persiste en wall-clock del APP_TIMEZONE (Bogotá), así
            // que EXTRACT(HOUR) directo ya da la hora local. `AT TIME ZONE` la
            // re-interpretaba contra la sesión PG (UTC) y corría la hora +5.
            $rows = DB::select(
                "SELECT
                    EXTRACT(HOUR FROM ordered_at)::int AS hour,
                    COUNT(*) AS orders,
                    COALESCE(SUM(total), 0) AS revenue
                FROM orders
                WHERE company_nit = ?
                  AND ordered_at  BETWEEN ? AND ?
                  AND status      = ANY(?)
                  {$branchFilter}
                GROUP BY 1
                ORDER BY 1 ASC",
                $branchId !== null
                    ? [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}', $branchId]
                    : [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}']
            );

            $byHour = collect($rows)->keyBy('hour');

            $heatmap = collect(range(0, 23))->map(fn ($h) => [
                'hour' => $h,
                'orders' => (int) ($byHour[$h]->orders ?? 0),
                'revenue' => (float) ($byHour[$h]->revenue ?? 0),
            ])->values()->all();

            $peakRow = collect($rows)->sortByDesc('orders')->first();

            return [
                'period' => $period,
                'timezone' => $tz,
                'heatmap' => $heatmap,
                'peak_hour' => $peakRow ? (int) $peakRow->hour : null,
                'peak_hour_orders' => $peakRow ? (int) $peakRow->orders : 0,
            ];
        });

        return ['data' => $data];
    }

    // ── Part 2: dynamic-period endpoints ──────────────────────────────────────

    /** @return array<string, mixed> */
    public function getSummary(string $companyNit, string $period, ?Carbon $dateFrom, ?Carbon $dateTo, ?string $branchId = null): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        $key = "metrics:{$companyNit}:summary:{$period}:b:".($branchId ?? 'all')
            .($period === 'custom' ? ':'.substr(md5("{$start}_{$end}"), 0, 8) : '');

        $data = Cache::flexible($key, [$this->summaryTtl, $this->summaryTtl * 2], function () use ($companyNit, $period, $start, $end, $branchId) {
            $revenueStatuses = config('orders.revenue');
            $activeStatuses = config('orders.operational');

            $inRevenue = "'".implode("','", $revenueStatuses)."'";
            $inActive = "'".implode("','", $activeStatuses)."'";
            $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

            $row = DB::selectOne(
                "SELECT
                    COUNT(*)::int                                                              AS total_orders,
                    COUNT(CASE WHEN status = 'completed'  THEN 1 END)::int                    AS completed_orders,
                    COUNT(CASE WHEN status = 'cancelled'  THEN 1 END)::int                    AS cancelled_orders,
                    COALESCE(SUM(CASE WHEN status IN ({$inRevenue}) THEN total ELSE 0 END), 0)::float AS total_revenue,
                    COUNT(CASE WHEN status IN ({$inActive}) THEN 1 END)::int                  AS orders_in_progress,
                    COUNT(CASE WHEN status IN ({$inRevenue}) THEN 1 END)::int                 AS revenue_count
                 FROM orders
                WHERE company_nit = ? AND ordered_at BETWEEN ? AND ? {$branchFilter}",
                $branchId !== null ? [$companyNit, $start, $end, $branchId] : [$companyNit, $start, $end]
            );

            $revenueCount = (int) ($row?->revenue_count ?? 0);
            $totalRevenue = (float) ($row?->total_revenue ?? 0);
            $abandonedCarts = CartSession::forCompany($companyNit)->inPeriod($start, $end)->abandoned()->count();

            return [
                'period' => $period,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'total_orders' => (int) ($row?->total_orders ?? 0),
                'completed_orders' => (int) ($row?->completed_orders ?? 0),
                'cancelled_orders' => (int) ($row?->cancelled_orders ?? 0),
                'abandoned_carts' => $abandonedCarts,
                'total_revenue' => $totalRevenue,
                'average_ticket' => $revenueCount > 0 ? round($totalRevenue / $revenueCount, 2) : 0,
                'orders_in_progress' => (int) ($row?->orders_in_progress ?? 0),
                'comparison' => $this->computeComparisonSummary($companyNit, $period, $start, $end, $branchId),
            ];
        });

        return ['data' => $data, 'cached' => true, 'cache_ttl' => $this->cacheTtl];
    }

    /** @return array<string, mixed>|null */
    private function computeComparisonSummary(string $companyNit, string $period, Carbon $currentStart, Carbon $currentEnd, ?string $branchId = null): ?array
    {
        $prevRange = $this->getPreviousPeriodRange($period, $currentStart, $currentEnd);
        if (! $prevRange) {
            return null;
        }

        [$prevStart, $prevEnd] = $prevRange;
        $revenueStatuses = config('orders.revenue');
        $inRevenue = "'".implode("','", $revenueStatuses)."'";
        $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

        $row = DB::selectOne(
            "SELECT
                COUNT(*)::int AS total_orders,
                COUNT(CASE WHEN status = 'completed' THEN 1 END)::int AS completed_orders,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END)::int AS cancelled_orders,
                COALESCE(SUM(CASE WHEN status IN ({$inRevenue}) THEN total ELSE 0 END), 0)::float AS total_revenue,
                COUNT(CASE WHEN status IN ({$inRevenue}) THEN 1 END)::int AS revenue_count
             FROM orders
            WHERE company_nit = ? AND ordered_at BETWEEN ? AND ? {$branchFilter}",
            $branchId !== null ? [$companyNit, $prevStart, $prevEnd, $branchId] : [$companyNit, $prevStart, $prevEnd]
        );

        // average_ticket usa el mismo denominador que getSummary (revenue_count)
        // para que la comparación sea homogénea. Antes usaba completed_orders y
        // distorsionaba el ticket al haber órdenes en estados intermedios.
        $revenueCount = (int) ($row?->revenue_count ?? 0);
        $totalRevenue = (float) ($row?->total_revenue ?? 0);
        $abandonedCarts = CartSession::forCompany($companyNit)->inPeriod($prevStart, $prevEnd)->abandoned()->count();

        $periodLabel = match ($period) {
            'today' => 'ayer',
            'week' => 'semana anterior',
            'month' => 'mes anterior',
            default => 'período anterior',
        };

        return [
            'period_label' => $periodLabel,
            'total_orders' => (int) ($row?->total_orders ?? 0),
            'completed_orders' => (int) ($row?->completed_orders ?? 0),
            'cancelled_orders' => (int) ($row?->cancelled_orders ?? 0),
            'abandoned_carts' => $abandonedCarts,
            'total_revenue' => $totalRevenue,
            'average_ticket' => $revenueCount > 0 ? round($totalRevenue / $revenueCount, 2) : 0,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function getPreviousPeriodRange(string $period, Carbon $currentStart, Carbon $currentEnd): ?array
    {
        return match ($period) {
            'today' => [$currentStart->copy()->subDay(), $currentEnd->copy()->subDay()],
            'week' => [$currentStart->copy()->subDays(7), $currentEnd->copy()->subDays(7)],
            'month' => [
                $currentStart->copy()->subMonth()->startOfMonth(),
                $currentStart->copy()->subMonth()->endOfMonth(),
            ],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function getOrderHeatmap(string $companyNit, string $period, ?Carbon $dateFrom, ?Carbon $dateTo, ?string $branchId = null): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        $key = "metrics:{$companyNit}:orders:heatmap:{$period}:b:".($branchId ?? 'all')
            .($period === 'custom' ? ':'.substr(md5("{$start}_{$end}"), 0, 8) : '');

        // flexible() sirve datos ligeramente obsoletos mientras recalcula en background
        $data = Cache::flexible($key, [$this->heatmapTtl, $this->heatmapTtl * 2], function () use ($companyNit, $period, $start, $end, $branchId) {
            $revenueStatuses = config('orders.revenue');
            $inRevenue = "'".implode("','", $revenueStatuses)."'";
            $tz = $this->timezone;
            $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

            $rows = DB::select(
                "SELECT
                    hour,
                    COUNT(*)::int AS orders,
                    COALESCE(SUM(rev), 0)::float AS revenue
                FROM (
                    SELECT
                        EXTRACT(HOUR FROM ordered_at)::int AS hour,
                        CASE WHEN status IN ({$inRevenue}) THEN total ELSE 0 END AS rev
                    FROM orders
                    WHERE company_nit = ?
                      AND ordered_at  BETWEEN ? AND ?
                      {$branchFilter}
                ) sub
                GROUP BY hour
                ORDER BY hour ASC",
                $branchId !== null ? [$companyNit, $start, $end, $branchId] : [$companyNit, $start, $end]
            );

            $byHour = collect($rows)->keyBy('hour');
            $maxOrders = (int) (collect($rows)->max('orders') ?? 0);

            $hours = collect(range(0, 23))->map(function ($h) use ($byHour, $maxOrders) {
                $ordersCount = (int) ($byHour[$h]->orders ?? 0);

                return [
                    'hour' => $h,
                    'orders_count' => $ordersCount,
                    'revenue' => (float) ($byHour[$h]->revenue ?? 0),
                    'intensity' => $maxOrders > 0 ? round($ordersCount / $maxOrders, 4) : 0.0,
                ];
            })->values()->all();

            $peakRow = collect($rows)->sortByDesc('orders')->first();

            return [
                'period' => $period,
                'timezone' => $tz,
                'hours' => $hours,
                'current_hour' => Carbon::now($this->timezone)->hour,
                'peak_hour' => $peakRow ? (int) $peakRow->hour : null,
                'peak_hour_orders' => $peakRow ? (int) $peakRow->orders : 0,
            ];
        });

        return ['data' => $data];
    }

    /** @return array<string, mixed> */
    public function getWeeklyHeatmap(string $companyNit, string $period, ?Carbon $dateFrom, ?Carbon $dateTo, ?string $branchId = null): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        $key = "metrics:{$companyNit}:orders:heatmap:weekly:{$period}:b:".($branchId ?? 'all')
            .($period === 'custom' ? ':'.substr(md5("{$start}_{$end}"), 0, 8) : '');

        // El heatmap semanal es la query más costosa (7 días × 24 horas)
        $data = Cache::flexible($key, [$this->heatmapTtl, $this->heatmapTtl * 2], function () use ($companyNit, $period, $start, $end, $branchId) {
            $revenueStatuses = config('orders.revenue');
            $tz = $this->timezone;
            $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

            $rows = DB::select(
                "SELECT
                    day_of_week,
                    hour,
                    COUNT(*)::int          AS orders,
                    COALESCE(SUM(total), 0)::float AS revenue
                FROM (
                    SELECT
                        EXTRACT(DOW FROM ordered_at)::int  AS day_of_week,
                        EXTRACT(HOUR FROM ordered_at)::int AS hour,
                        total
                    FROM orders
                    WHERE company_nit = ?
                      AND ordered_at  BETWEEN ? AND ?
                      AND status      = ANY(?)
                      {$branchFilter}
                ) sub
                GROUP BY day_of_week, hour
                ORDER BY day_of_week, hour",
                $branchId !== null
                    ? [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}', $branchId]
                    : [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}']
            );

            $byDayHour = collect($rows)->keyBy(fn ($r) => "{$r->day_of_week}_{$r->hour}");

            $cells = [];
            foreach (range(0, 6) as $dow) {
                foreach (range(0, 23) as $h) {
                    $row = $byDayHour["{$dow}_{$h}"] ?? null;
                    $cells[] = [
                        'day' => $dow,
                        'hour' => $h,
                        'orders' => (int) ($row?->orders ?? 0),
                        'revenue' => (float) ($row?->revenue ?? 0),
                    ];
                }
            }

            $maxOrders = collect($cells)->max('orders');

            return [
                'period' => $period,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'timezone' => $tz,
                'cells' => $cells,
                'max_orders' => (int) ($maxOrders ?? 0),
            ];
        });

        return ['data' => $data];
    }

    /** @return array<string, mixed> */
    public function getTopItems(string $companyNit, string $period, ?Carbon $dateFrom, ?Carbon $dateTo, int $limit = 10, ?string $branchId = null): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        $key = "metrics:{$companyNit}:items:top:{$period}:b:".($branchId ?? 'all')
            .($period === 'custom' ? ':'.substr(md5("{$start}_{$end}"), 0, 8) : '');

        $data = Cache::flexible($key, [$this->chartTtl, $this->chartTtl * 2], function () use ($companyNit, $period, $start, $end, $limit, $branchId) {
            $revenueStatuses = config('orders.revenue');
            $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

            $rows = DB::select(
                "SELECT
                    item->>'name'   AS name,
                    SUM(CAST(NULLIF(item->>'quantity','') AS INTEGER))::int     AS count,
                    SUM(CAST(NULLIF(item->>'price','') AS NUMERIC)
                        * CAST(NULLIF(item->>'quantity','') AS INTEGER))::float AS revenue
                FROM orders,
                    json_array_elements(items) AS item
                WHERE company_nit = ?
                  AND ordered_at  BETWEEN ? AND ?
                  AND status      = ANY(?)
                  {$branchFilter}
                GROUP BY item->>'name'
                ORDER BY count DESC, revenue DESC
                LIMIT ?",
                $branchId !== null
                    ? [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}', $branchId, $limit]
                    : [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}', $limit]
            );

            return [
                'period' => $period,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'limit' => $limit,
                'items' => collect($rows)->map(fn ($r) => [
                    'name' => $r->name,
                    'count' => (int) $r->count,
                    'revenue' => (float) $r->revenue,
                ])->values()->all(),
                'total_unique_items' => count($rows),
            ];
        });

        return ['data' => $data];
    }

    /**
     * Margen por plato en el período. Agrega `orders.items` (JSON) por id del ítem
     * sumando ingreso (price*qty) y costo (cost*qty), calcula margen y excluye
     * platos sin costo registrado (cost IS NULL en el snapshot).
     *
     * @return array<string, mixed>
     */
    public function getDishMargin(string $companyNit, string $period, ?Carbon $dateFrom, ?Carbon $dateTo, int $limit = 50, ?string $branchId = null): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        $key = "metrics:{$companyNit}:dishes:margin:{$period}:{$limit}:b:".($branchId ?? 'all')
            .($period === 'custom' ? ':'.substr(md5("{$start}_{$end}"), 0, 8) : '');

        $data = Cache::flexible($key, [$this->chartTtl, $this->chartTtl * 2], function () use ($companyNit, $period, $start, $end, $limit, $branchId) {
            $revenueStatuses = config('orders.revenue');
            $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

            $rows = DB::select(
                "SELECT
                    item->>'id'   AS item_id,
                    item->>'name' AS name,
                    SUM(CAST(NULLIF(item->>'quantity','') AS INTEGER))::int AS units_sold,
                    SUM(CAST(NULLIF(item->>'price','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER))::float AS gross_revenue,
                    SUM(CAST(NULLIF(item->>'cost','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER))::float AS gross_cost,
                    -- promedios para mostrar precio/costo de referencia (último snapshot dominante).
                    AVG(CAST(NULLIF(item->>'price','') AS NUMERIC))::float AS avg_price,
                    AVG(CAST(NULLIF(item->>'cost','') AS NUMERIC))::float  AS avg_cost
                FROM orders,
                    json_array_elements(items) AS item
                WHERE company_nit = ?
                  AND ordered_at  BETWEEN ? AND ?
                  AND status      = ANY(?)
                  {$branchFilter}
                  AND item->>'cost' IS NOT NULL
                GROUP BY item->>'id', item->>'name'
                HAVING SUM(CAST(NULLIF(item->>'price','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER)) > 0
                ORDER BY (SUM(CAST(NULLIF(item->>'price','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER))
                        - SUM(CAST(NULLIF(item->>'cost','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER))) DESC
                LIMIT ?",
                $branchId !== null
                    ? [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}', $branchId, $limit]
                    : [$companyNit, $start, $end, '{'.implode(',', $revenueStatuses).'}', $limit]
            );

            $items = collect($rows)->map(function ($r) {
                $revenue = (float) $r->gross_revenue;
                $cost = (float) $r->gross_cost;
                $marginAmount = round($revenue - $cost, 2);
                $marginPct = $revenue > 0 ? round(($marginAmount / $revenue) * 100, 2) : 0.0;

                return [
                    'item_id' => $r->item_id,
                    'name' => $r->name,
                    'units_sold' => (int) $r->units_sold,
                    'avg_price' => round((float) $r->avg_price, 2),
                    'avg_cost' => round((float) $r->avg_cost, 2),
                    'gross_revenue' => round($revenue, 2),
                    'gross_cost' => round($cost, 2),
                    'margin_amount' => $marginAmount,
                    'margin_pct' => $marginPct,
                ];
            })->values()->all();

            return [
                'period' => $period,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'limit' => $limit,
                'items' => $items,
                'total_unique_items' => count($items),
            ];
        });

        return ['data' => $data];
    }

    /** @return array<string, mixed> */
    public function getCartAbandonment(string $companyNit, string $period, ?Carbon $dateFrom, ?Carbon $dateTo, ?string $branchId = null): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        $key = "metrics:{$companyNit}:carts:abandonment:{$period}:b:".($branchId ?? 'all')
            .($period === 'custom' ? ':'.substr(md5("{$start}_{$end}"), 0, 8) : '');

        $data = Cache::flexible($key, [$this->metricsTtl, $this->metricsTtl * 2], function () use ($companyNit, $period, $start, $end) {
            $total = CartSession::forCompany($companyNit)->inPeriod($start, $end)->count();
            $abandoned = CartSession::forCompany($companyNit)->inPeriod($start, $end)->abandoned()->count();

            $revenueStatuses = config('orders.revenue');
            $revenueRow = Order::forCompany($companyNit)
                ->inPeriod($start, $end)
                ->whereIn('status', $revenueStatuses)
                ->selectRaw('COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS cnt')
                ->first();

            $avgTicket = (int) ($revenueRow?->cnt ?? 0) > 0
                ? ((float) $revenueRow->revenue / (int) $revenueRow->cnt)
                : 0;

            $conversionRate = $total > 0 ? round((1 - ($abandoned / $total)) * 100, 2) : 100.0;

            return [
                'period' => $period,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'total_initiated' => $total,
                'converted' => $total - $abandoned,
                'abandoned' => $abandoned,
                'conversion_rate' => $conversionRate,
                'estimated_lost_revenue' => round($abandoned * $avgTicket, 2),
            ];
        });

        return ['data' => $data];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    /**
     * SMS enviados al cliente por cambios de estado de orden (#275, Fase 3).
     *
     * Conteo agregado en SQL (`COUNT(*) GROUP BY branch_id`) — nunca iterando en
     * PHP (§13). Devuelve total de empresa + desglose por sede. Respeta el scope
     * de sede: si `$branchId` viene seteado (vista de una sede) filtra a ella; si
     * es null (consolidado vía `branch.consolidate`) agrega todas las sedes.
     *
     * @return array<string, mixed>
     */
    public function getSmsCounts(string $companyNit, ?string $branchId, string $period, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        $branchKey = $branchId ?? 'all';
        $key = "metrics:sms:counts:{$companyNit}:{$branchKey}:{$period}:{$start->getTimestamp()}:{$end->getTimestamp()}";

        $data = Cache::flexible($key, [$this->metricsTtl, $this->metricsTtl * 5], function () use ($companyNit, $branchId, $start, $end) {
            // Escape de BranchScope justificado: el desglose por sede necesita ver
            // todas las sedes de la empresa; el filtro por sede activa se aplica
            // explícitamente con $branchId (inyectado por branch.access).
            $base = OrderSmsNotification::withoutBranchScope()
                ->where('company_nit', $companyNit)
                ->where('status', 'sent')
                // Solo SMS reales (con costo por segmento SNS). Las notificaciones
                // que salieron gratis por WhatsApp (channel='whatsapp') no cuentan
                // como SMS enviados: inflarían el costo reportado.
                ->where('channel', 'sms')
                ->whereBetween('sent_at', [$start, $end]);

            if ($branchId !== null) {
                $base->where('branch_id', $branchId);
            }

            $rows = (clone $base)
                ->selectRaw('branch_id, COUNT(*) AS total')
                ->groupBy('branch_id')
                ->pluck('total', 'branch_id');

            $branchNames = Branch::query()
                ->whereIn('id', $rows->keys()->all())
                ->pluck('name', 'id');

            $byBranch = $rows->map(fn ($count, $bid): array => [
                'branch_id' => $bid,
                'branch_name' => $branchNames[$bid] ?? '—',
                'total' => (int) $count,
            ])->values()->all();

            return [
                'total' => (int) $rows->sum(),
                'by_branch' => $byBranch,
            ];
        });

        return [
            'period' => ['from' => $start->toIso8601String(), 'to' => $end->toIso8601String(), 'label' => $period],
            'data' => $data,
        ];
    }

    /**
     * Escaneos del menú QR (#294): lee menu_scan_daily_rollup (historia durable,
     * agregada por AggregateMenuScansJob hasta D-1) y le une el día en curso
     * agregado en vivo desde menu_scan_events (is_bot = false) — sin eso el
     * período "today" siempre daría cero porque el rollup corre para ayer.
     *
     * La agregación pesada ocurre en SQL (rollup pre-agregado + GROUP BY del día
     * vivo); el regroup en PHP opera sobre filas ya agregadas (≤ días × sedes ×
     * mesas). `unique_sessions` es la suma de únicos por (día, mesa, sede) — la
     * granularidad del rollup, que no conserva session_id: una sesión que escanea
     * dos mesas o dos días cuenta en cada grupo (aproximación no reconstruible).
     *
     * @return array<string, mixed>
     */
    public function getMenuScans(string $companyNit, ?string $branchId, string $period, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        $branchKey = $branchId ?? 'all';
        $key = "metrics:menu-scans:{$companyNit}:{$branchKey}:{$period}:{$start->getTimestamp()}:{$end->getTimestamp()}";

        $data = Cache::flexible($key, [$this->metricsTtl, $this->metricsTtl * 5], function () use ($companyNit, $branchId, $start, $end) {
            $todayStart = Carbon::now($this->timezone)->startOfDay();
            $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

            // `scan_date < hoy` en el rollup evita doble conteo si el job se
            // re-ejecutó manualmente para el día en curso (upsert idempotente).
            $sql = "
                SELECT scan_date::text AS scan_date, branch_id, table_number, total_scans::int AS total_scans, unique_sessions::int AS unique_sessions
                FROM menu_scan_daily_rollup
                WHERE company_nit = ?
                  AND scan_date >= ?::date AND scan_date <= ?::date AND scan_date < ?::date
                  {$branchFilter}
                UNION ALL
                SELECT ?::text, branch_id, COALESCE(table_number, ''), COUNT(*)::int, COUNT(DISTINCT session_id)::int
                FROM menu_scan_events
                WHERE company_nit = ?
                  AND scanned_at >= ? AND scanned_at <= ?
                  AND is_bot = false
                  {$branchFilter}
                GROUP BY branch_id, COALESCE(table_number, '')
            ";

            $liveStart = $start->greaterThan($todayStart) ? $start : $todayStart;

            $bindings = [$companyNit, $start->toDateString(), $end->toDateString(), $todayStart->toDateString()];
            if ($branchId !== null) {
                $bindings[] = $branchId;
            }
            $bindings = array_merge($bindings, [$todayStart->toDateString(), $companyNit, $liveStart->toDateTimeString(), $end->toDateTimeString()]);
            if ($branchId !== null) {
                $bindings[] = $branchId;
            }

            $rows = collect(DB::select($sql, $bindings));

            $daily = $rows->groupBy('scan_date')
                ->map(fn ($group, $date): array => [
                    'date' => $date,
                    'total_scans' => (int) $group->sum('total_scans'),
                    'unique_sessions' => (int) $group->sum('unique_sessions'),
                ])
                ->sortKeys()->values()->all();

            $byTable = $rows->filter(fn ($row): bool => $row->table_number !== '')
                ->groupBy('table_number')
                ->map(fn ($group, $table): array => [
                    'table_number' => (string) $table,
                    'total_scans' => (int) $group->sum('total_scans'),
                ])
                ->sortByDesc('total_scans')->take(10)->values()->all();

            $byBranch = [];
            if ($branchId === null) {
                $grouped = $rows->groupBy('branch_id')->map(fn ($group): int => (int) $group->sum('total_scans'));
                $branchNames = Branch::query()->whereIn('id', $grouped->keys()->all())->pluck('name', 'id');
                $byBranch = $grouped->map(fn (int $count, string $bid): array => [
                    'branch_id' => $bid,
                    'branch_name' => $branchNames[$bid] ?? '—',
                    'total_scans' => $count,
                ])->sortByDesc('total_scans')->values()->all();
            }

            return [
                'total_scans' => (int) $rows->sum('total_scans'),
                'unique_sessions' => (int) $rows->sum('unique_sessions'),
                'daily' => $daily,
                'by_table' => $byTable,
                'by_branch' => $byBranch,
            ];
        });

        return [
            'period' => ['from' => $start->toIso8601String(), 'to' => $end->toIso8601String(), 'label' => $period],
            'data' => $data,
        ];
    }

    private function resolveDates(string $period, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        if ($period === 'custom' && $dateFrom && $dateTo) {
            return [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()];
        }

        return $this->resolvePeriod($period);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * @return array{pending: int, in_kitchen: int, ready: int, in_transit: int}
     */
    private function buildActiveBreakdown(string $companyNit): array
    {
        $statuses = config('orders.operational');

        $rows = Order::forCompany($companyNit)
            ->whereIn('status', $statuses)
            ->selectRaw('status, COUNT(*) AS cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return [
            'pending' => (int) ($rows['pending'] ?? 0),
            'in_kitchen' => (int) ($rows['in_kitchen'] ?? 0),
            'ready' => (int) ($rows['ready'] ?? 0),
            'in_transit' => (int) ($rows['in_transit'] ?? 0),
        ];
    }

    private function getDeltaPercent(float $current, float $previous): float
    {
        return round((($current - $previous) / max(abs($previous), 1)) * 100, 2);
    }

    private function getTrendDirection(float $delta): string
    {
        if ($delta > 0.5) {
            return 'up';
        }

        if ($delta < -0.5) {
            return 'down';
        }

        return 'neutral';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(string $period): array
    {
        $now = Carbon::now($this->timezone);

        return match ($period) {
            // Part 1 legacy names
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            // Part 2 names
            'week' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth()->startOfDay(), $now->copy()->endOfMonth()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }
}
