<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RestaurantMenu;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Calcula KPIs de food cost (costo de alimentos) en tiempo real.
 *
 * Reglas contables (CLAUDE.md):
 *  - El KPI agregado usa el snapshot histórico fiel `orders.cost` por línea
 *    (campo `items[].cost` del JSON), no recálculo on-the-fly. Cada item de la
 *    orden ya guarda el costo del momento de la venta — esto sobrevive a
 *    cambios futuros en recetas o precios de proveedor.
 *  - Items con `cost IS NULL` o `cost = 0` se excluyen del cost_ratio agregado
 *    y se reportan en `coverage` para que el dueño sepa qué % de las ventas
 *    tiene costo conocido.
 *  - Solo cuentan órdenes en estados de revenue (`config('orders.revenue')`).
 *  - Toda agregación se hace en SQL, nunca iterando órdenes en PHP.
 *
 * Histórico:
 *  - `itemHistory()` lee de `menu_item_cost_history` (snapshots diarios).
 *  - `ensureTodaySnapshot()` realiza lazy backfill si el cron no ha corrido,
 *    protegido por `Cache::lock` para evitar dobles ejecuciones concurrentes y
 *    throttle de 6h para no recomputar a cada request.
 */
final class FoodCostMetricsService
{
    private string $timezone;

    private int $summaryTtl;

    public function __construct(
        private readonly RecipeCostService $recipeCostService,
    ) {
        $this->timezone = (string) config('metrics.timezone', 'America/Bogota');
        $this->summaryTtl = (int) config('metrics.dashboard_metrics_cache_ttl', 300);
    }

    /**
     * Resumen del food cost del período: KPIs agregados, breakdown por plato y
     * cobertura (qué % de las ventas tiene costo registrado).
     *
     * @return array{data: array<string, mixed>}
     */
    public function summary(string $companyNit, string $period, ?Carbon $dateFrom, ?Carbon $dateTo, int $limit = 100, ?string $branchId = null): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        // (costeo multibodega / multisede) El food cost se filtra por sede
        // activa; `branchId === null` = consolidado (todas las sedes). El SQL es
        // crudo, así que el BranchScope NO aplica — hay que filtrar a mano y
        // separar el cache por sede.
        $key = "metrics:{$companyNit}:foodcost:summary:{$period}:{$limit}:b:".($branchId ?? 'all')
            .($period === 'custom' ? ':'.substr(md5("{$start}_{$end}"), 0, 8) : '');

        $data = Cache::flexible($key, [$this->summaryTtl, $this->summaryTtl * 2], function () use ($companyNit, $period, $start, $end, $limit, $branchId) {
            $revenueStatuses = config('orders.revenue');
            $statusesArray = '{'.implode(',', $revenueStatuses).'}';
            $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';

            // Agregado por ítem. Items sin costo (cost NULL o 0) se incluyen en
            // la lista pero NO en los totales `with_cost_*` que alimentan el
            // cost_ratio agregado.
            $rows = DB::select(
                "SELECT
                    item->>'id'   AS item_id,
                    item->>'name' AS name,
                    SUM(CAST(NULLIF(item->>'quantity','') AS INTEGER))::int AS units_sold,
                    SUM(CAST(NULLIF(item->>'price','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER))::float AS gross_revenue,
                    SUM(CASE
                        WHEN item->>'cost' IS NOT NULL AND CAST(NULLIF(item->>'cost','') AS NUMERIC) > 0
                        THEN CAST(NULLIF(item->>'cost','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER)
                        ELSE 0
                    END)::float AS gross_cost,
                    SUM(CASE
                        WHEN item->>'cost' IS NOT NULL AND CAST(NULLIF(item->>'cost','') AS NUMERIC) > 0
                        THEN CAST(NULLIF(item->>'price','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER)
                        ELSE 0
                    END)::float AS revenue_with_cost,
                    SUM(CASE
                        WHEN item->>'cost' IS NOT NULL AND CAST(NULLIF(item->>'cost','') AS NUMERIC) > 0
                        THEN CAST(NULLIF(item->>'quantity','') AS INTEGER)
                        ELSE 0
                    END)::int AS units_with_cost,
                    AVG(CAST(NULLIF(item->>'price','') AS NUMERIC))::float AS avg_price,
                    AVG(NULLIF(CAST(NULLIF(item->>'cost','') AS NUMERIC), 0))::float AS avg_cost
                FROM orders,
                    json_array_elements(items) AS item
                WHERE company_nit = ?
                  AND ordered_at  BETWEEN ? AND ?
                  AND status      = ANY(?)
                  {$branchFilter}
                GROUP BY item->>'id', item->>'name'
                ORDER BY SUM(CAST(NULLIF(item->>'price','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER)) DESC
                LIMIT ?",
                $branchId !== null
                    ? [$companyNit, $start, $end, $statusesArray, $branchId, $limit]
                    : [$companyNit, $start, $end, $statusesArray, $limit]
            );

            $items = [];
            $aggGrossRevenue = 0.0;
            $aggGrossCost = 0.0;
            $aggRevenueWithCost = 0.0;
            $aggUnitsSold = 0;
            $aggUnitsWithCost = 0;

            foreach ($rows as $r) {
                $revenue = (float) $r->gross_revenue;
                $cost = (float) $r->gross_cost;
                $unitsWithCost = (int) $r->units_with_cost;
                $hasCost = $unitsWithCost > 0 && $cost > 0;

                $marginPct = null;
                $costRatio = null;
                if ($hasCost) {
                    $revenueWithCost = (float) $r->revenue_with_cost;
                    $marginPct = $revenueWithCost > 0
                        ? round((($revenueWithCost - $cost) / $revenueWithCost) * 100, 2)
                        : null;
                    $costRatio = $revenueWithCost > 0
                        ? round(($cost / $revenueWithCost) * 100, 2)
                        : null;
                }

                $items[] = [
                    'item_id' => $r->item_id,
                    'name' => $r->name,
                    'units_sold' => (int) $r->units_sold,
                    'units_with_cost' => $unitsWithCost,
                    'avg_price' => round((float) ($r->avg_price ?? 0), 2),
                    'avg_cost' => $hasCost ? round((float) ($r->avg_cost ?? 0), 2) : null,
                    'gross_revenue' => round($revenue, 2),
                    'gross_cost' => round($cost, 2),
                    'margin_pct' => $marginPct,
                    'cost_ratio' => $costRatio,
                    'has_cost' => $hasCost,
                ];

                $aggGrossRevenue += $revenue;
                $aggGrossCost += $cost;
                $aggRevenueWithCost += (float) $r->revenue_with_cost;
                $aggUnitsSold += (int) $r->units_sold;
                $aggUnitsWithCost += $unitsWithCost;
            }

            $costRatioPct = $aggRevenueWithCost > 0
                ? round(($aggGrossCost / $aggRevenueWithCost) * 100, 2)
                : null;
            $marginPct = $aggRevenueWithCost > 0
                ? round((($aggRevenueWithCost - $aggGrossCost) / $aggRevenueWithCost) * 100, 2)
                : null;
            $coveragePct = $aggUnitsSold > 0
                ? round(($aggUnitsWithCost / $aggUnitsSold) * 100, 2)
                : 0.0;

            // Meta del último snapshot diario para alertar al usuario si el
            // scheduler no está corriendo (ver capa 3 del plan).
            $snapshotMeta = $this->snapshotMeta($companyNit);

            return [
                'period' => $period,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'totals' => [
                    'gross_revenue' => round($aggGrossRevenue, 2),
                    'gross_revenue_with_cost' => round($aggRevenueWithCost, 2),
                    'gross_cost' => round($aggGrossCost, 2),
                    'cost_ratio_pct' => $costRatioPct,
                    'margin_pct' => $marginPct,
                    'units_sold' => $aggUnitsSold,
                    'units_with_cost' => $aggUnitsWithCost,
                    'coverage_pct' => $coveragePct,
                ],
                'items' => $items,
                'snapshot_meta' => $snapshotMeta,
            ];
        });

        return ['data' => $data];
    }

    /**
     * Histórico de costo computado (cron diario) para un ítem.
     *
     * Si el item ya no existe en la `structure` actual del menú activo, igual
     * se devuelven sus snapshots con `archived = true` para que la UI lo
     * indique.
     *
     * @return array{data: array<string, mixed>}
     */
    public function itemHistory(string $companyNit, string $menuItemId, string $period, ?Carbon $dateFrom, ?Carbon $dateTo, ?string $branchId = null): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);

        // (#costeo-multibodega) menu_item_cost_history es branch-keyed: puede
        // haber una fila por (sede, día). Con sede activa se filtra a ella; en
        // consolidado se PROMEDIA el costo del ítem entre sedes para devolver una
        // sola serie por día (sin puntos duplicados). El SQL crudo no recibe el
        // BranchScope, así que se filtra a mano.
        $branchFilter = $branchId !== null ? 'AND branch_id = ?' : '';
        $bindings = $branchId !== null
            ? [$companyNit, $menuItemId, $branchId, $start->toDateString(), $end->toDateString()]
            : [$companyNit, $menuItemId, $start->toDateString(), $end->toDateString()];

        $rows = DB::select(
            "SELECT snapshot_date,
                    AVG(computed_cost) AS computed_cost,
                    MAX(source) AS source,
                    MAX(menu_item_name) AS menu_item_name,
                    MAX(menu_item_category) AS menu_item_category
             FROM menu_item_cost_history
             WHERE company_nit = ?
               AND menu_item_id = ?
               {$branchFilter}
               AND snapshot_date BETWEEN ?::date AND ?::date
             GROUP BY snapshot_date
             ORDER BY snapshot_date ASC",
            $bindings
        );

        $points = array_map(fn ($r) => [
            'date' => $r->snapshot_date,
            'cost' => round((float) $r->computed_cost, 2),
            'source' => $r->source,
        ], $rows);

        // Resolver si el item sigue vivo en el menú activo — O(1) vía índice
        // memoizado (antes recorría todas las categorías por cada lookup).
        $archived = true;
        $name = null;
        $category = null;
        $menu = RestaurantMenu::query()->forCompany($companyNit)->active()->first();
        if ($menu) {
            $entry = $menu->findMenuItem($menuItemId);
            if ($entry !== null) {
                $archived = false;
                $name = $entry['name'] ?? null;
                $category = $entry['category'] ?? null;
            }
        }
        if ($archived && ! empty($rows)) {
            $name = $rows[count($rows) - 1]->menu_item_name;
            $category = $rows[count($rows) - 1]->menu_item_category;
        }

        return [
            'data' => [
                'menu_item_id' => $menuItemId,
                'name' => $name,
                'category' => $category,
                'archived' => $archived,
                'period' => $period,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'points' => $points,
            ],
        ];
    }

    /**
     * Lazy backfill: si no hay snapshots del día actual para esta empresa,
     * los genera síncronamente. Protegido por `Cache::lock` (dedupe) y
     * throttle de 6h (no recomputar a cada request si ya se intentó).
     *
     * Devuelve cuántos snapshots se insertaron (0 si ya estaban o si el lock
     * está tomado por otro proceso).
     */
    public function ensureTodaySnapshot(string $companyNit): int
    {
        $today = Carbon::now($this->timezone)->toDateString();
        $throttleKey = "foodcost:snapshot:throttle:{$companyNit}:{$today}";

        // Throttle: solo intentamos una vez cada 6h por empresa.
        if (Cache::has($throttleKey)) {
            return 0;
        }

        // Si ya hay snapshots de hoy, marcamos throttle y salimos.
        $exists = DB::table('menu_item_cost_history')
            ->where('company_nit', $companyNit)
            ->whereDate('snapshot_date', $today)
            ->exists();
        if ($exists) {
            Cache::put($throttleKey, 1, 6 * 3600);

            return 0;
        }

        $lock = Cache::lock("foodcost:snapshot:lock:{$companyNit}:{$today}", 60);
        if (! $lock->get()) {
            return 0;
        }

        try {
            $count = $this->generateSnapshotsForCompany($companyNit, $today);
            Cache::put($throttleKey, 1, 6 * 3600);

            return $count;
        } finally {
            $lock->release();
        }
    }

    /**
     * Genera (upsert) snapshots de costo para todos los items del menú activo
     * de una empresa, en una fecha dada. Reutilizado por el cron y por el
     * lazy backfill.
     */
    public function generateSnapshotsForCompany(string $companyNit, string $date): int
    {
        // Multi-sede: cada sede tiene su propio menú activo y no se
        // comparte entre sedes. Generamos snapshots por sede (atribuidos a su
        // branch_id) para que el histórico de food cost cubra TODAS las cartas,
        // no solo una. withoutBranchScope porque el cron/backfill no tiene
        // active_branch_id; igual filtramos por empresa.
        $menus = RestaurantMenu::withoutBranchScope()
            ->forCompany($companyNit)
            ->active()
            ->get();

        if ($menus->isEmpty()) {
            return 0;
        }

        $rows = [];
        $now = now();

        foreach ($menus as $menu) {
            // menu_item_cost_history requiere branch_id NOT NULL: si el menú no
            // tiene sede asignada, no se puede atribuir el costo — se omite.
            if ($menu->branch_id === null) {
                continue;
            }

            foreach ($menu->structure['categories'] ?? [] as $category) {
                $catName = $category['name'] ?? null;
                foreach ($category['items'] ?? [] as $item) {
                    $itemId = (string) ($item['id'] ?? '');
                    if ($itemId === '' || strlen($itemId) > 64) {
                        continue;
                    }

                    // Costeo por sede (#costeo-multibodega): se pasa branch_id
                    // del menú para no mezclar recetas de sedes con cartas
                    // clonadas (mismo menu_item_id).
                    $hasRecipe = $this->recipeCostService->hasRecipe($companyNit, $menu->branch_id, $itemId);
                    $source = $hasRecipe ? 'recipe' : 'manual';
                    $cost = $hasRecipe
                        ? $this->recipeCostService->compute($companyNit, $menu->branch_id, $itemId)['total_cost']
                        : (string) round((float) ($item['cost'] ?? 0), 2);

                    $rows[] = [
                        // DB::table()->upsert() bypasea HasUuids — UUID explícito obligatorio.
                        'id' => (string) Str::uuid(),
                        'company_nit' => $companyNit,
                        'branch_id' => $menu->branch_id,
                        'menu_id' => $menu->id,
                        'menu_item_id' => $itemId,
                        'menu_item_name' => (string) ($item['name'] ?? '(sin nombre)'),
                        'menu_item_category' => $catName,
                        'snapshot_date' => $date,
                        'computed_cost' => $cost,
                        'source' => $source,
                        'created_at' => $now,
                    ];
                }
            }
        }

        if (empty($rows)) {
            return 0;
        }

        // Dedupe por (sede, item): la unique key es (company, branch, item,
        // date). Branch-keyear conserva el snapshot de CADA sede (antes "ganaba
        // la última sede" y se perdía el histórico de las cartas clonadas). El
        // dedupe sigue siendo defensivo contra dos categorías con el mismo item.
        $rows = collect($rows)
            ->keyBy(fn ($r) => $r['branch_id'].'|'.$r['menu_item_id'])
            ->values()
            ->all();

        // Upsert: si ya existe el (company, branch, item, date) actualiza el costo.
        // `menu_id` va en el update para que un item que cambió de carta dentro
        // de la misma sede no deje el snapshot apuntando a la carta vieja.
        DB::table('menu_item_cost_history')->upsert(
            $rows,
            ['company_nit', 'branch_id', 'menu_item_id', 'snapshot_date'],
            ['computed_cost', 'source', 'menu_item_name', 'menu_item_category', 'menu_id']
        );

        return count($rows);
    }

    /**
     * @return array{last_snapshot_at: string|null, items_snapshotted: int, scheduler_lag_hours: int|null}
     */
    private function snapshotMeta(string $companyNit): array
    {
        $row = DB::table('menu_item_cost_history')
            ->where('company_nit', $companyNit)
            ->selectRaw('MAX(created_at) AS last_at, COUNT(*) AS cnt')
            ->first();

        $lastAt = $row?->last_at;
        $lag = null;
        if ($lastAt) {
            $lag = (int) Carbon::parse($lastAt, $this->timezone)->diffInHours(Carbon::now($this->timezone));
        }

        return [
            'last_snapshot_at' => $lastAt,
            'items_snapshotted' => (int) ($row?->cnt ?? 0),
            'scheduler_lag_hours' => $lag,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDates(string $period, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        if ($period === 'custom' && $dateFrom && $dateTo) {
            return [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()];
        }

        $now = Carbon::now($this->timezone);

        return match ($period) {
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }
}
