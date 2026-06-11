<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Export diario de morosos a S3 (uso interno flexyflow).
 *
 * Genera un CSV con la foto del día de empresas en {past_due, suspended} y lo
 * sube a `config('billing.delinquent_export_disk')` bajo
 * `config('billing.delinquent_export_prefix')/{YYYY-MM-DD}.csv`.
 *
 * Siempre genera archivo aunque no haya morosos (header sin filas) — sirve
 * de "heartbeat" para que ops sepa que el job corrió.
 *
 * Cron: diario 05:30 onOneServer (routes/console.php), después del cron de
 * past_due para tomar el estado ya recalculado.
 */
class ExportDelinquentCompaniesCommand extends Command
{
    protected $signature = 'billing:export-delinquent
                            {--date= : Fecha de referencia YYYY-MM-DD (default: hoy)}';

    protected $description = 'Genera CSV con empresas en past_due/suspended y lo sube a S3 interno';

    public function __construct(private readonly AuditService $auditService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dateOption = $this->option('date');
        $today = $dateOption ? Carbon::parse($dateOption) : now();
        $todayDate = $today->toDateString();

        $rows = DB::select('
            SELECT
                c.nit,
                c.legal_name,
                c.commercial_name,
                c.status,
                c.past_due_started_at,
                c.expected_block_at,
                c.payment_blocked_at,
                COUNT(i.id) FILTER (WHERE i.status IN (?, ?) AND i.due_date < ?) AS overdue_invoices_count,
                COALESCE(SUM(i.amount) FILTER (WHERE i.status IN (?, ?) AND i.due_date < ?), 0) AS total_due_cop
            FROM companies c
            LEFT JOIN invoices i ON i.company_nit = c.nit
            WHERE c.status IN (?, ?)
            GROUP BY c.nit, c.legal_name, c.commercial_name, c.status, c.past_due_started_at,
                     c.expected_block_at, c.payment_blocked_at
            ORDER BY c.past_due_started_at ASC NULLS LAST
        ', ['overdue', 'pending', $todayDate, 'overdue', 'pending', $todayDate, 'past_due', 'suspended']);

        $csv = fopen('php://temp', 'w+');
        fputcsv($csv, [
            'nit', 'legal_name', 'commercial_name', 'status',
            'past_due_started_at', 'expected_block_at', 'payment_blocked_at',
            'overdue_invoices_count', 'total_due_cop',
        ]);

        foreach ($rows as $row) {
            fputcsv($csv, [
                $row->nit,
                $row->legal_name,
                $row->commercial_name,
                $row->status,
                $row->past_due_started_at,
                $row->expected_block_at,
                $row->payment_blocked_at,
                $row->overdue_invoices_count,
                $row->total_due_cop,
            ]);
        }

        rewind($csv);
        $csvContent = stream_get_contents($csv);
        fclose($csv);

        $disk = config('billing.delinquent_export_disk', 's3_documents');
        $prefix = rtrim((string) config('billing.delinquent_export_prefix', 'flexyflow-internal/delinquent-companies'), '/');
        $key = "{$prefix}/{$todayDate}.csv";

        Storage::disk($disk)->put($key, $csvContent, ['visibility' => 'private']);

        $bytes = strlen($csvContent);
        $rowCount = count($rows);

        $this->info("Export listo: {$rowCount} empresas, {$bytes} bytes — s3://{$disk}/{$key}");

        $this->auditService->log('billing.delinquent_exported', null, null, [
            'date' => $todayDate,
            'row_count' => $rowCount,
            'bytes' => $bytes,
            'disk' => $disk,
            's3_key' => $key,
        ]);

        return self::SUCCESS;
    }
}
