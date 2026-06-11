<?php

namespace App\Jobs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agrega los eventos crudos de un día específico (default: ayer) al rollup diario.
 *
 * - Solo eventos con is_bot = false (filas marcadas por BotDetectionService).
 * - INSERT … ON CONFLICT … DO UPDATE → idempotente: re-ejecutar la corrida actualiza,
 *   no duplica. Útil si el job falla a la mitad del día y se reintenta.
 * - Lee solo de la(s) partición(es) cuyo rango contiene el día → partition pruning
 *   automático del planner Postgres.
 */
class AggregateMenuScansJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ?string $dateString = null,
    ) {}

    public function handle(): void
    {
        $date = $this->dateString
            ? CarbonImmutable::parse($this->dateString)->startOfDay()
            : CarbonImmutable::now()->subDay()->startOfDay();

        $rows = $this->aggregate($date);

        if ($rows === 0) {
            return;
        }

        Log::info('menu_scan.rollup.aggregated', [
            'scan_date' => $date->toDateString(),
            'rows_upserted' => $rows,
        ]);
    }

    private function aggregate(CarbonInterface $date): int
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->addDay()->startOfDay();

        // El UPSERT mantiene un único rollup por (company_nit, scan_date, table_number).
        // COALESCE convierte NULL → '' para mantener una sola fila por "QR sin mesa".
        $sql = <<<'SQL'
            INSERT INTO menu_scan_daily_rollup (
                company_nit, scan_date, table_number,
                total_scans, unique_sessions, created_at, updated_at
            )
            SELECT
                company_nit,
                ?::date AS scan_date,
                COALESCE(table_number, '') AS table_number,
                COUNT(*) AS total_scans,
                COUNT(DISTINCT session_id) AS unique_sessions,
                now(), now()
            FROM menu_scan_events
            WHERE scanned_at >= ? AND scanned_at < ?
              AND is_bot = false
            GROUP BY company_nit, COALESCE(table_number, '')
            ON CONFLICT (company_nit, scan_date, table_number) DO UPDATE
                SET total_scans = EXCLUDED.total_scans,
                    unique_sessions = EXCLUDED.unique_sessions,
                    updated_at = now();
        SQL;

        return DB::affectingStatement($sql, [
            $start->toDateString(),
            $start->toDateTimeString(),
            $end->toDateTimeString(),
        ]);
    }
}
