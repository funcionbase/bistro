<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\FoodCostMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Snapshot diario del costo computado de cada ítem de menú activo.
 *
 * - Cron: 04:00 (registrado en routes/console.php).
 * - Idempotente: usa upsert por (company_nit, menu_item_id, snapshot_date).
 * - Si el cron no corre, FoodCostMetricsService::ensureTodaySnapshot()
 *   dispara este mismo cómputo en demand al primer acceso del día.
 *
 * Flags:
 *  --company=NIT  : limita el snapshot a una sola empresa (debug / regenerar).
 *  --date=Y-m-d   : fecha del snapshot (default: hoy en zona horaria configurada).
 */
class SnapshotMenuItemCostsCommand extends Command
{
    protected $signature = 'foodcost:snapshot-daily {--company=} {--date=}';

    protected $description = 'Genera snapshots diarios de food cost por ítem para el histórico (issue #113).';

    public function handle(FoodCostMetricsService $foodCostService): int
    {
        $tz = (string) config('metrics.timezone', 'America/Bogota');
        $date = $this->option('date') ?: Carbon::now($tz)->toDateString();
        $companyNit = $this->option('company');

        $companies = $companyNit
            ? Company::query()->where('nit', $companyNit)->pluck('nit')
            : Company::query()->pluck('nit');

        if ($companies->isEmpty()) {
            $this->warn('No hay empresas para procesar.');

            return self::SUCCESS;
        }

        $totalSnapshots = 0;
        $bar = $this->output->createProgressBar($companies->count());
        $bar->start();

        foreach ($companies as $nit) {
            try {
                $count = $foodCostService->generateSnapshotsForCompany($nit, $date);
                $totalSnapshots += $count;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Error en empresa {$nit}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Snapshots generados: {$totalSnapshots} (fecha={$date}, empresas={$companies->count()})");

        return self::SUCCESS;
    }
}
