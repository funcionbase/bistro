<?php

declare(strict_types=1);

namespace App\Services\Alerts\Evaluators;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Services\Alerts\AlertEventDraft;
use Illuminate\Support\Facades\DB;

/**
 * Dispara alerta cuando el stock total de un insumo cae al/por debajo del
 * min_stock declarado (suma stocks de todas las bodegas activas del insumo).
 *
 * threshold se ignora — el min_stock por insumo ya es el umbral.
 * period_days se ignora — es snapshot del presente.
 *
 * Severity:
 *  - critical si stock = 0
 *  - warning si 0 < stock <= min_stock
 *
 * Solo aplica a insumos no archivados con al menos una bodega activa con
 * stock registrado y min_stock > 0.
 */
final class LowStockEvaluator implements Evaluator
{
    public function evaluate(AlertRule $rule): array
    {
        $rows = DB::select(
            'SELECT
                i.id AS ingredient_id,
                i.name AS name,
                i.unit AS unit,
                SUM(s.quantity)::float AS stock,
                SUM(s.min_stock)::float AS min_stock
             FROM ingredients i
             INNER JOIN ingredient_stocks s ON s.ingredient_id = i.id
             WHERE i.company_nit = ?
               AND i.archived_at IS NULL
             GROUP BY i.id, i.name, i.unit
             HAVING SUM(s.min_stock) > 0
                AND SUM(s.quantity) <= SUM(s.min_stock)',
            [$rule->company_nit]
        );

        $drafts = [];
        foreach ($rows as $row) {
            $stock = (float) $row->stock;
            $min = (float) $row->min_stock;
            $severity = $stock <= 0
                ? AlertEvent::SEVERITY_CRITICAL
                : AlertEvent::SEVERITY_WARNING;

            $drafts[] = new AlertEventDraft(
                type: AlertRule::TYPE_LOW_STOCK,
                severity: $severity,
                targetType: AlertEvent::TARGET_INGREDIENT,
                targetId: (string) $row->ingredient_id,
                payload: [
                    'name' => $row->name,
                    'unit' => $row->unit,
                    'stock' => round($stock, 3),
                    'min_stock' => round($min, 3),
                ],
            );
        }

        return $drafts;
    }
}
