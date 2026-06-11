<?php

declare(strict_types=1);

namespace App\Services\Alerts\Evaluators;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Services\Alerts\AlertEventDraft;
use Illuminate\Support\Facades\DB;

/**
 * Dispara alerta para platos activos que no han tenido ventas en los últimos
 * `period_days` (threshold se ignora — period_days es la palanca real).
 *
 * "Plato activo" = aparece en restaurant_menus.menu_json[*].items[*] del menú
 * activo de la empresa. Si no aparece ahí, no es candidato (no es plato
 * vigente).
 *
 * Severity siempre info — es señal débil, no acción urgente.
 */
final class ItemLowVolumeEvaluator implements Evaluator
{
    public function evaluate(AlertRule $rule): array
    {
        $periodDays = max(1, (int) $rule->period_days);
        $revenueStatuses = config('orders.revenue');
        $statusesArray = '{'.implode(',', $revenueStatuses).'}';

        $rows = DB::select(
            'WITH menu_items AS (
                SELECT DISTINCT
                    item->>\'id\' AS item_id,
                    item->>\'name\' AS name
                FROM restaurant_menus rm,
                     jsonb_array_elements((rm.structure::jsonb)->\'categories\') AS category,
                     jsonb_array_elements(category->\'items\') AS item
                WHERE rm.company_nit = ?
                  AND rm.status = \'active\'
                  AND item->>\'id\' IS NOT NULL
                  AND COALESCE((item->>\'available\')::boolean, true) = true
            ),
            sold AS (
                SELECT DISTINCT item->>\'id\' AS item_id
                FROM orders, jsonb_array_elements(items::jsonb) AS item
                WHERE company_nit = ?
                  AND status = ANY (?::varchar[])
                  AND ordered_at >= NOW() - (? || \' days\')::interval
                  AND item->>\'id\' IS NOT NULL
            )
            SELECT menu_items.item_id, menu_items.name
              FROM menu_items
              LEFT JOIN sold ON sold.item_id = menu_items.item_id
             WHERE sold.item_id IS NULL',
            [$rule->company_nit, $rule->company_nit, $statusesArray, $periodDays]
        );

        $drafts = [];
        foreach ($rows as $row) {
            $drafts[] = new AlertEventDraft(
                type: AlertRule::TYPE_ITEM_LOW_VOLUME,
                severity: AlertEvent::SEVERITY_INFO,
                targetType: AlertEvent::TARGET_MENU_ITEM,
                targetId: (string) $row->item_id,
                payload: [
                    'name' => $row->name,
                    'period_days' => $periodDays,
                ],
            );
        }

        return $drafts;
    }
}
