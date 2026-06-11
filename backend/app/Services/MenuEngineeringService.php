<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Menu engineering: clasifica platos en una matriz 2x2 cruzando popularidad
 * (eje X: unidades vendidas / total) contra margen unitario absoluto en COP
 * (eje Y: avg_price - avg_cost).
 *
 * Cuadrantes (umbrales = mediana de cada eje sobre los items con costo):
 *  - star    : popularidad >= mediana_pop  AND margen >= mediana_margen
 *  - cow     : popularidad >= mediana_pop  AND margen <  mediana_margen
 *  - puzzle  : popularidad <  mediana_pop  AND margen >= mediana_margen
 *  - dog     : popularidad <  mediana_pop  AND margen <  mediana_margen
 *  - unknown : item sin costo conocido (no clasificable; no entra al matrix
 *              ni al cálculo de medianas, solo se reporta en summary.unknown).
 *
 * Reglas contables (CLAUDE.md):
 *  - Toda agregación se hace en SQL, nunca iterando órdenes en PHP.
 *  - Solo cuentan órdenes en estados de revenue (`config('orders.revenue')`).
 *  - Lee el snapshot histórico fiel `orders.items[].cost` del JSON (no recálculo
 *    on-the-fly) para que un cambio de receta hoy no altere el matrix de ayer.
 */
final class MenuEngineeringService
{
    private string $timezone;

    private int $cacheTtl;

    /** @var array<string, array{title: string, recommendation: string}> */
    private const QUADRANT_META = [
        'star' => [
            'title' => 'Estrella',
            'recommendation' => 'Promociónalo y mantén su calidad — es tu plato más rentable y popular.',
        ],
        'cow' => [
            'title' => 'Vaca lechera',
            'recommendation' => 'Sube el precio gradualmente o reduce su costo — vendes mucho pero margen bajo.',
        ],
        'puzzle' => [
            'title' => 'Puzzle',
            'recommendation' => 'Empújalo con happy hour o relocación en el menú — buen margen, pero pocos clientes lo piden.',
        ],
        'dog' => [
            'title' => 'Perro',
            'recommendation' => 'Considera retirarlo o rediseñarlo — bajo margen y baja demanda.',
        ],
    ];

    public function __construct()
    {
        $this->timezone = (string) config('metrics.timezone', 'America/Bogota');
        $this->cacheTtl = (int) config('metrics.dashboard_metrics_cache_ttl', 300);
    }

    /**
     * Construye el matrix para un período.
     *
     * @return array{data: array<string, mixed>}
     */
    public function matrix(string $companyNit, string $period, ?Carbon $dateFrom, ?Carbon $dateTo, int $limit = 200): array
    {
        [$start, $end] = $this->resolveDates($period, $dateFrom, $dateTo);
        $key = "metrics:{$companyNit}:menueng:matrix:{$period}:{$limit}"
            .($period === 'custom' ? ':'.substr(md5("{$start}_{$end}"), 0, 8) : '');

        $data = Cache::flexible($key, [$this->cacheTtl, $this->cacheTtl * 2], function () use ($companyNit, $period, $start, $end, $limit) {
            $statusesArray = '{'.implode(',', config('orders.revenue')).'}';

            $rows = DB::select(
                "SELECT
                    item->>'id'   AS item_id,
                    item->>'name' AS name,
                    SUM(CAST(NULLIF(item->>'quantity','') AS INTEGER))::int AS units_sold,
                    AVG(CAST(NULLIF(item->>'price','') AS NUMERIC))::float AS avg_price,
                    AVG(NULLIF(CAST(NULLIF(item->>'cost','') AS NUMERIC), 0))::float AS avg_cost,
                    SUM(CASE
                        WHEN item->>'cost' IS NOT NULL AND CAST(NULLIF(item->>'cost','') AS NUMERIC) > 0
                        THEN CAST(NULLIF(item->>'quantity','') AS INTEGER)
                        ELSE 0
                    END)::int AS units_with_cost,
                    SUM(CAST(NULLIF(item->>'price','') AS NUMERIC) * CAST(NULLIF(item->>'quantity','') AS INTEGER))::float AS gross_revenue
                FROM orders,
                    json_array_elements(items) AS item
                WHERE company_nit = ?
                  AND ordered_at  BETWEEN ? AND ?
                  AND status      = ANY(?)
                GROUP BY item->>'id', item->>'name'
                ORDER BY SUM(CAST(NULLIF(item->>'quantity','') AS INTEGER)) DESC
                LIMIT ?",
                [$companyNit, $start, $end, $statusesArray, $limit]
            );

            $totalUnits = 0;
            $classifiable = [];
            $unknownCount = 0;
            $unknownUnits = 0;
            foreach ($rows as $r) {
                $units = (int) $r->units_sold;
                $totalUnits += $units;

                $hasCost = ((int) $r->units_with_cost) > 0 && $r->avg_cost !== null && (float) $r->avg_cost > 0;
                if (! $hasCost) {
                    $unknownCount++;
                    $unknownUnits += $units;

                    continue;
                }

                $classifiable[] = [
                    'item_id' => $r->item_id,
                    'name' => $r->name,
                    'units_sold' => $units,
                    'avg_price' => round((float) $r->avg_price, 2),
                    'avg_cost' => round((float) $r->avg_cost, 2),
                    'contribution_margin' => round((float) $r->avg_price - (float) $r->avg_cost, 2),
                    'gross_revenue' => round((float) $r->gross_revenue, 2),
                ];
            }

            // Eje X: popularidad relativa = unidades / total unidades vendidas
            // (incluyendo unknown en el denominador; refleja el peso real del
            // plato en el ticket promedio).
            foreach ($classifiable as &$d) {
                $d['popularity_pct'] = $totalUnits > 0
                    ? round(($d['units_sold'] / $totalUnits) * 100, 2)
                    : 0.0;
                $d['total_contribution'] = round($d['contribution_margin'] * $d['units_sold'], 2);
            }
            unset($d);

            $popValues = array_column($classifiable, 'popularity_pct');
            $marginValues = array_column($classifiable, 'contribution_margin');
            $medPop = $this->median($popValues);
            $medMargin = $this->median($marginValues);

            $summary = ['star' => 0, 'cow' => 0, 'puzzle' => 0, 'dog' => 0];
            foreach ($classifiable as &$d) {
                $popHigh = $d['popularity_pct'] >= $medPop;
                $marginHigh = $d['contribution_margin'] >= $medMargin;
                $quadrant = match (true) {
                    $popHigh && $marginHigh => 'star',
                    $popHigh && ! $marginHigh => 'cow',
                    ! $popHigh && $marginHigh => 'puzzle',
                    default => 'dog',
                };
                $d['quadrant'] = $quadrant;
                $d['recommendation'] = self::QUADRANT_META[$quadrant]['recommendation'];
                $summary[$quadrant]++;
            }
            unset($d);

            usort($classifiable, fn ($a, $b) => $b['total_contribution'] <=> $a['total_contribution']);

            return [
                'period' => $period,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'thresholds' => [
                    'popularity_pct' => round($medPop, 2),
                    'contribution_margin' => round($medMargin, 2),
                ],
                'summary' => [
                    'stars' => $summary['star'],
                    'cows' => $summary['cow'],
                    'puzzles' => $summary['puzzle'],
                    'dogs' => $summary['dog'],
                    'unknown' => $unknownCount,
                    'classifiable' => count($classifiable),
                    'total_units' => $totalUnits,
                    'unknown_units' => $unknownUnits,
                ],
                'dishes' => $classifiable,
            ];
        });

        return ['data' => $data];
    }

    /**
     * Mediana clásica (promedio de los dos centrales si la cardinalidad es par).
     *
     * @param  array<int, float|int>  $values
     */
    private function median(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        sort($values);
        $mid = (int) floor($n / 2);

        return $n % 2 === 1
            ? (float) $values[$mid]
            : (float) (($values[$mid - 1] + $values[$mid]) / 2);
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
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }
}
