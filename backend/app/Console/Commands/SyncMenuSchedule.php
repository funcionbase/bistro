<?php

namespace App\Console\Commands;

use App\Services\MenuSchedulerService;
use Illuminate\Console\Command;

/**
 * Activa/desactiva menús programados según el día de la semana actual.
 *
 * Cron: se ejecuta diariamente (configurado en routes/console.php o el scheduler de Laravel).
 * Muta la tabla restaurant_menus (campo status). Si ningún menú cumple la condición del día,
 * todos quedan inactivos y el restaurante opera sin menú activo hasta el siguiente sync.
 * Idempotente: solo actualiza registros que realmente cambian de estado.
 */
class SyncMenuSchedule extends Command
{
    protected $signature = 'menus:sync-schedule';

    protected $description = 'Activate/deactivate scheduled menus based on the current day of the week';

    public function __construct(private readonly MenuSchedulerService $scheduler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->scheduler->sync();

        $this->info("Menu schedule synced: {$result['synced']} updated, {$result['activated']} activated.");

        return self::SUCCESS;
    }
}
