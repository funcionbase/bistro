<?php

namespace App\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Poda eventos crudos de `menu_scan_events` anteriores al cutoff de retención
 * (default: 90 días). Reemplaza el `DROP PARTITION` previo — se retiró el
 * particionamiento RANGE mensual porque generaba tablas históricas que se
 * acumulaban con el tiempo.
 *
 * `menu_scan_daily_rollup` ya tiene los agregados pre-calculados, así que
 * perder el detalle crudo > 90 días no afecta reportes. Si en algún momento
 * el volumen crece (millones de filas/mes) se puede:
 *   - Bajar el cutoff vía `MENU_SCAN_RETENTION_DAYS` (env).
 *   - Re-introducir particionamiento (revisar git log de este archivo).
 *
 * El nombre se mantiene como `DropOldMenuScanPartitionsJob` para no romper
 * referencias en `routes/console.php`; la implementación interna ya no
 * toca particiones.
 *
 * Se ejecuta una vez al día (scheduler en routes/console.php).
 */
class DropOldMenuScanPartitionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ?int $retentionDays = null,
    ) {}

    public function handle(): void
    {
        $days = $this->retentionDays ?? (int) config('menu_scan.retention_days', 90);
        $cutoff = CarbonImmutable::now()->subDays($days);

        $deleted = DB::table('menu_scan_events')
            ->where('scanned_at', '<', $cutoff->toIso8601String())
            ->delete();

        if ($deleted > 0) {
            Log::info('menu_scan.events.pruned', [
                'cutoff' => $cutoff->toIso8601String(),
                'rows_deleted' => $deleted,
                'retention_days' => $days,
            ]);
        }
    }
}
