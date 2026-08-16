<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Genera las facturas mensuales de suscripción para todas las empresas activas.
 *
 * Cron: primer día del mes (post-pago — factura el mes anterior).
 * Muta `invoices` + `invoice_lines`. Idempotente:
 *  - BillingService::generateMonthlyInvoices() omite suscripciones con factura
 *    no-void para el período.
 *  - UNIQUE parcial DB sobre (subscription_id, period_from, period_to) WHERE
 *    status!='voided' es la última defensa contra duplicados cross-instance.
 *
 * N-instance safe (add-on CLAUDE.md §12):
 *  - Schedule::->onOneServer() + ->withoutOverlapping(60) en routes/console.php.
 *  - CACHE_STORE=database (postgres) compartido entre EC2.
 *  - Cada invoice se crea dentro de DB::transaction.
 *  - DB::afterCommit dispatch del EmitDianInvoiceJob solo si la invoice committea.
 *
 * --month acepta `YYYY-MM`; sin él, factura el MES ANTERIOR (post-pago).
 */
class GenerateMonthlyInvoicesCommand extends Command
{
    protected $signature = 'billing:generate-monthly-invoices
                            {--month= : Mes a facturar en formato YYYY-MM (default: mes anterior — post-pago)}';

    protected $description = 'Genera facturas mensuales para el período indicado (default: mes vencido)';

    public function __construct(private readonly BillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $monthOption = $this->option('month');
        $forMonth = $monthOption
            ? Carbon::createFromFormat('Y-m', (string) $monthOption)->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        $this->info("Generando facturas para período: {$forMonth->format('Y-m')} (post-pago)");

        $invoiceIds = $this->billingService->generateMonthlyInvoices($forMonth);

        $this->info('Facturas generadas: '.count($invoiceIds));

        return self::SUCCESS;
    }
}
