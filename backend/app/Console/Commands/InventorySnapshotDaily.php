<?php

namespace App\Console\Commands;

use App\Services\WarehouseStockHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Genera el snapshot diario del stock por bodega para series temporales.
 *
 * Idempotente: vuelve a correr el mismo día sin duplicar (upsert por
 * (warehouse_id, ingredient_id, snapshot_date)).
 *
 * Programado en routes/console.php a las 03:30 — después del cierre operativo
 * (los locales cierran a más tardar 23:30) y antes del food cost snapshot
 * que corre a las 04:00.
 *
 * Uso manual:
 *   php artisan inventory:snapshot-daily            # snapshot de ayer
 *   php artisan inventory:snapshot-daily --date=2026-05-01
 *   php artisan inventory:snapshot-daily --backfill=30   # últimos 30 días
 */
class InventorySnapshotDaily extends Command
{
    protected $signature = 'inventory:snapshot-daily
        {--date= : Fecha específica (YYYY-MM-DD). Default: ayer.}
        {--backfill= : Si se pasa N, snapshota los últimos N días hacia atrás desde --date (o ayer).}';

    protected $description = 'Snapshot diario del stock por bodega (warehouse_stock_snapshots).';

    public function handle(WarehouseStockHistoryService $history): int
    {
        $baseDate = $this->resolveDate();
        $backfill = $this->option('backfill');

        if ($backfill !== null) {
            $days = (int) $backfill;
            if ($days <= 0) {
                $this->error('--backfill debe ser un entero positivo.');

                return self::INVALID;
            }

            $total = 0;
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = $baseDate->copy()->subDays($i);
                $count = $history->snapshotDaily($date);
                $total += $count;
                $this->info(sprintf('  [%s] %d filas snapshot.', $date->toDateString(), $count));
            }

            Log::info('inventory.snapshot.daily.backfill', [
                'from' => $baseDate->copy()->subDays($days - 1)->toDateString(),
                'to' => $baseDate->toDateString(),
                'total_rows' => $total,
            ]);

            $this->info(sprintf('Backfill terminado: %d filas total.', $total));

            return self::SUCCESS;
        }

        $count = $history->snapshotDaily($baseDate);

        Log::info('inventory.snapshot.daily', [
            'date' => $baseDate->toDateString(),
            'rows' => $count,
        ]);

        $this->info(sprintf('Snapshot %s: %d filas.', $baseDate->toDateString(), $count));

        return self::SUCCESS;
    }

    private function resolveDate(): Carbon
    {
        $option = $this->option('date');

        if ($option) {
            return Carbon::parse(CarbonImmutable::parse((string) $option)->startOfDay());
        }

        return now()->subDay()->startOfDay();
    }
}
