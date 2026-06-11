<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DianResolution;
use Illuminate\Console\Command;

/**
 * Genera entradas en `alert_events` (si existe el subsistema)
 * para resoluciones DIAN próximas a vencer. Hoy solo loguea — la integración
 * con AlertEvent se hace cuando se confirme el modelo del proyecto.
 */
class DianResolutionExpirationAlertCommand extends Command
{
    protected $signature = 'dian:resolution-expiration-alert';

    protected $description = 'Detecta resoluciones DIAN próximas a vencer (<30 días) y loguea para alertas.';

    public function handle(): int
    {
        $threshold = (int) config('dian.resolution_expiration_alert_days', 30);
        $cutoff = now()->addDays($threshold)->toDateString();

        $expiring = DianResolution::query()
            ->where('is_active', true)
            ->whereDate('valid_until', '<=', $cutoff)
            ->get();

        foreach ($expiring as $res) {
            $this->warn(sprintf(
                'Resolución DIAN id=%d, prefix=%s vence el %s (empresa=%s).',
                $res->id,
                $res->prefix,
                $res->valid_until?->toDateString() ?? 'n/a',
                $res->company_nit,
            ));
        }

        $this->info(sprintf('Revisión completada. %d resoluciones próximas a vencer.', $expiring->count()));

        return self::SUCCESS;
    }
}
