<?php

declare(strict_types=1);

namespace App\Services\Alerts\Evaluators;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Services\Alerts\AlertEventDraft;
use Illuminate\Support\Facades\DB;

/**
 * Dispara alerta cuando el costo unitario promedio (purchase_order_items.unit_cost)
 * de un insumo subió un % superior al threshold en los últimos `period_days`.
 *
 * threshold expresa la fracción de incremento (0.10 = +10%).
 *
 * Compara: AVG(unit_cost) en (now - period .. now/2) vs AVG(unit_cost) en
 * (period .. period*2) — ventana móvil simétrica. Se requieren al menos 2
 * compras en cada ventana para evitar falsos positivos por una compra
 * outlier.
 */
final class CostIncreaseEvaluator implements Evaluator
{
    public function evaluate(AlertRule $rule): array
    {
        $thresholdFraction = (float) $rule->threshold;
        $periodDays = max(2, (int) $rule->period_days);

        $rows = DB::select(
            'SELECT
                i.id AS ingredient_id,
                i.name AS name,
                i.unit AS unit,
                AVG(CASE WHEN po.received_date >= NOW() - (? || \' days\')::interval THEN poi.unit_cost END) AS recent_avg,
                COUNT(CASE WHEN po.received_date >= NOW() - (? || \' days\')::interval THEN 1 END) AS recent_count,
                AVG(CASE WHEN po.received_date >= NOW() - (? || \' days\')::interval
                          AND po.received_date <  NOW() - (? || \' days\')::interval
                         THEN poi.unit_cost END) AS prior_avg,
                COUNT(CASE WHEN po.received_date >= NOW() - (? || \' days\')::interval
                            AND po.received_date <  NOW() - (? || \' days\')::interval
                           THEN 1 END) AS prior_count
             FROM purchase_order_items poi
             INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
             INNER JOIN ingredients i ON i.id = poi.ingredient_id
             WHERE po.company_nit = ?
               AND po.received_date IS NOT NULL
               AND po.received_date >= NOW() - (? || \' days\')::interval
               AND poi.unit_cost > 0
               AND i.archived_at IS NULL
             GROUP BY i.id, i.name, i.unit
             HAVING COUNT(CASE WHEN po.received_date >= NOW() - (? || \' days\')::interval THEN 1 END) >= 2
                AND COUNT(CASE WHEN po.received_date >= NOW() - (? || \' days\')::interval
                                AND po.received_date <  NOW() - (? || \' days\')::interval
                               THEN 1 END) >= 2',
            [
                $periodDays,                                  // recent window upper
                $periodDays,                                  // recent count
                $periodDays * 2, $periodDays,                  // prior window
                $periodDays * 2, $periodDays,                  // prior count
                $rule->company_nit,
                $periodDays * 2,                              // global lower bound
                $periodDays,                                  // recent HAVING
                $periodDays * 2, $periodDays,                  // prior HAVING
            ]
        );

        $drafts = [];
        foreach ($rows as $row) {
            $recent = (float) $row->recent_avg;
            $prior = (float) $row->prior_avg;
            if ($prior <= 0 || $recent <= 0) {
                continue;
            }
            $increase = ($recent - $prior) / $prior;
            if ($increase < $thresholdFraction) {
                continue;
            }

            $severity = ($increase >= $thresholdFraction * 2)
                ? AlertEvent::SEVERITY_CRITICAL
                : AlertEvent::SEVERITY_WARNING;

            $drafts[] = new AlertEventDraft(
                type: AlertRule::TYPE_COST_INCREASE,
                severity: $severity,
                targetType: AlertEvent::TARGET_INGREDIENT,
                targetId: (string) $row->ingredient_id,
                payload: [
                    'name' => $row->name,
                    'unit' => $row->unit,
                    'increase' => round($increase, 4),
                    'threshold' => round($thresholdFraction, 4),
                    'recent_avg' => round($recent, 2),
                    'prior_avg' => round($prior, 2),
                    'period_days' => $periodDays,
                ],
            );
        }

        return $drafts;
    }
}
