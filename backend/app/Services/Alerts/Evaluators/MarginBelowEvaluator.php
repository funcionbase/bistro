<?php

declare(strict_types=1);

namespace App\Services\Alerts\Evaluators;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Services\Alerts\AlertEventDraft;
use Illuminate\Support\Facades\DB;

/**
 * Dispara alerta cuando el margen de un plato cae por debajo del umbral
 * (threshold expresado como fracción, e.g. 0.30 = 30%).
 *
 * Cálculo: agrega ventas+costos de los últimos `period_days` desde el JSON de
 * `orders.items`, igual que FoodCostMetricsService — items con cost NULL/0 se
 * excluyen (sin costo conocido no se puede afirmar pérdida).
 *
 * Severity:
 *  - critical si margin < threshold * 0.7 (muy por debajo)
 *  - warning  en otro caso
 */
final class MarginBelowEvaluator implements Evaluator
{
    public function evaluate(AlertRule $rule): array
    {
        $thresholdFraction = (float) $rule->threshold;
        $periodDays = max(1, (int) $rule->period_days);
        $revenueStatuses = config('orders.revenue');
        $statusesArray = '{'.implode(',', $revenueStatuses).'}';

        $rows = DB::select(
            'SELECT
                item->>\'id\'   AS item_id,
                item->>\'name\' AS name,
                SUM(CAST(NULLIF(item->>\'quantity\',\'\') AS INTEGER))::int AS units_sold,
                SUM(CAST(NULLIF(item->>\'price\',\'\') AS NUMERIC) * CAST(NULLIF(item->>\'quantity\',\'\') AS INTEGER))::float AS revenue,
                SUM(CAST(NULLIF(item->>\'cost\',\'\') AS NUMERIC) * CAST(NULLIF(item->>\'quantity\',\'\') AS INTEGER))::float AS cost
             FROM orders, jsonb_array_elements(items::jsonb) AS item
             WHERE company_nit = ?
               AND status = ANY (?::varchar[])
               AND ordered_at >= NOW() - (? || \' days\')::interval
               AND item->>\'cost\' IS NOT NULL
               AND CAST(NULLIF(item->>\'cost\',\'\') AS NUMERIC) > 0
             GROUP BY item->>\'id\', item->>\'name\'
             HAVING SUM(CAST(NULLIF(item->>\'price\',\'\') AS NUMERIC) * CAST(NULLIF(item->>\'quantity\',\'\') AS INTEGER)) > 0',
            [$rule->company_nit, $statusesArray, $periodDays]
        );

        $drafts = [];
        foreach ($rows as $row) {
            if ($row->revenue <= 0) {
                continue;
            }
            $margin = ($row->revenue - $row->cost) / $row->revenue;
            if ($margin >= $thresholdFraction) {
                continue;
            }

            $severity = ($margin < $thresholdFraction * 0.7)
                ? AlertEvent::SEVERITY_CRITICAL
                : AlertEvent::SEVERITY_WARNING;

            $drafts[] = new AlertEventDraft(
                type: AlertRule::TYPE_MARGIN_BELOW,
                severity: $severity,
                targetType: AlertEvent::TARGET_MENU_ITEM,
                targetId: (string) $row->item_id,
                payload: [
                    'name' => $row->name,
                    'margin' => round($margin, 4),
                    'threshold' => round($thresholdFraction, 4),
                    'units_sold' => (int) $row->units_sold,
                    'revenue' => round((float) $row->revenue, 2),
                    'cost' => round((float) $row->cost, 2),
                    'period_days' => $periodDays,
                ],
            );
        }

        return $drafts;
    }
}
