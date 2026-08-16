<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Canary del scheduler en multi-EC2.
 *
 * Programado cada minuto con ->onOneServer(). Si cache_locks funciona
 * correctamente, CloudWatch Logs debe mostrar exactamente UNA entrada
 * `healthcheck.heartbeat` por minuto, sin importar cuántos nodos haya
 * en el ASG (1, 2, N).
 *
 * Si aparecen ≥ 2 entradas con `host` distintos en el mismo minuto:
 *   → fallo de ->onOneServer() / cache_locks.
 *   → bloqueante para subir DesiredCapacity (T5).
 */
class HealthcheckHeartbeat extends Command
{
    protected $signature = 'healthcheck:heartbeat';

    protected $description = 'Canary: confirma que el scheduler corre 1 vez por intervalo en multi-EC2';

    public function handle(): int
    {
        Log::info('healthcheck.heartbeat', [
            'host' => gethostname(),
            'time' => now()->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
