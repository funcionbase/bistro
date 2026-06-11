<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Marca facturas vencidas y delega el recálculo de status a BillingService.
 *
 * Cron: diario 04:30 (routes/console.php). Muta `invoices.status → overdue` y
 * llama BillingService::recalculateCompanyStatus() para cada empresa afectada
 * (activa→past_due, past_due→suspended si pasaron 3 meses, suspended→active si liquidó).
 * Idempotente — correr varias veces el mismo día deja todo igual.
 * --date permite simular un día diferente para correcciones manuales.
 */
class MarkOverdueInvoicesCommand extends Command
{
    protected $signature = 'billing:mark-overdue-invoices
                            {--date= : Fecha de referencia YYYY-MM-DD (default: hoy)}';

    protected $description = 'Marca facturas vencidas y actualiza estado de empresas en past_due';

    public function __construct(private readonly BillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dateOption = $this->option('date');
        $today = $dateOption ? Carbon::parse($dateOption) : now();

        $this->info("Procesando past_due para fecha: {$today->toDateString()}");

        $invoiceIds = $this->billingService->markOverdueInvoices($today);

        $this->info('Facturas marcadas como vencidas: '.count($invoiceIds));

        return self::SUCCESS;
    }
}
