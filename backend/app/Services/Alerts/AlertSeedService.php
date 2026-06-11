<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\AlertRule;

/**
 * Garantiza que las 4 reglas de alerta existan para una empresa con defaults
 * razonables. Idempotente — usa `firstOrCreate` para que llamadas repetidas
 * sean inocuas.
 *
 * Defaults justificados:
 *  - margin_below: 30% es la línea típica del sector restaurante COL para
 *    cubrir food cost (30-35%) + labor + utilities + utilidad.
 *  - cost_increase: +10% en 7 días = subida material en una semana.
 *  - item_low_volume: 14 días sin venta = candidato a retirar/repensar.
 *  - low_stock: threshold no se usa (min_stock por insumo manda).
 */
final class AlertSeedService
{
    /** @var array<string, array{threshold: float, period_days: int}> */
    private const DEFAULTS = [
        AlertRule::TYPE_MARGIN_BELOW => ['threshold' => 0.30, 'period_days' => 7],
        AlertRule::TYPE_COST_INCREASE => ['threshold' => 0.10, 'period_days' => 7],
        AlertRule::TYPE_ITEM_LOW_VOLUME => ['threshold' => 0, 'period_days' => 14],
        AlertRule::TYPE_LOW_STOCK => ['threshold' => 0, 'period_days' => 1],
    ];

    public function ensureDefaults(string $companyNit): void
    {
        foreach (self::DEFAULTS as $type => $config) {
            AlertRule::query()->firstOrCreate(
                ['company_nit' => $companyNit, 'type' => $type],
                [
                    'threshold' => $config['threshold'],
                    'period_days' => $config['period_days'],
                    'enabled' => true,
                    'notify_dashboard' => true,
                    'notify_whatsapp' => false,
                ]
            );
        }
    }
}
