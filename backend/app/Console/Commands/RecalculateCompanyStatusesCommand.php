<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\BillingService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Reactivación de empresas en mora (companies:recalculate-statuses).
 *
 * Itera empresas en `past_due` o `suspended` y delega el recálculo a
 * `BillingService::recalculateCompanyStatus()` — la única función autorizada
 * para mutar `companies.status` por motivos de facturación.
 *
 * Casos que cubre cada corrida:
 *  - `past_due` → `active` cuando el cliente liquidó todas las invoices
 *    vencidas (típico: comprobante aprobado en la última ventana de 4h).
 *  - `past_due` → `suspended` cuando se cumplen los meses de gracia
 *    (`expected_block_at <= today`).
 *  - `suspended` → `active` cuando se liquida la deuda completa.
 *
 * Idempotencia: el método del servicio ya hace `lockForUpdate + transaction`
 * por empresa y no genera audit ni notificaciones si no hay cambio de status.
 * Correr el comando N veces el mismo día deja el mismo resultado.
 *
 * N-instance safe: en `routes/console.php` se programa con `onOneServer()` +
 * `withoutOverlapping(30)`. Requiere cache store compartido (PDN usa redis).
 *
 * Por qué no integrarlo en `billing:mark-overdue-invoices`: ese cron corre
 * diario a las 04:30 y depende de marcar invoices como `overdue`. La
 * reactivación necesita una frecuencia mayor para que un cliente que pague
 * al medio día no espere ~24h. Cron cada 4h da hasta ~4h de retraso máximo
 * para reflejar el pago, suficiente para UX y barato en costo (chunks de 200).
 */
class RecalculateCompanyStatusesCommand extends Command
{
    /** @var string */
    protected $signature = 'companies:recalculate-statuses
        {--date= : Fecha de referencia YYYY-MM-DD (default: hoy)}
        {--chunk=200 : Tamaño del chunk para iterar empresas en mora.}';

    /** @var string */
    protected $description = 'Recalcula el status de empresas en past_due/suspended y aplica transiciones (reactivación, bloqueo).';

    public function __construct(private readonly BillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = $this->resolveDate();
        $chunk = max(1, (int) $this->option('chunk'));

        $this->info("Recalculando status de empresas en mora — referencia {$today->toDateString()}");

        $processed = 0;
        $transitions = [
            'past_due_cleared' => 0,
            'past_due_to_suspended' => 0,
            'no_change' => 0,
        ];

        Company::query()
            ->whereIn('status', ['past_due', 'suspended'])
            ->orderBy('nit')
            ->chunkById($chunk, function ($companies) use ($today, &$processed, &$transitions) {
                foreach ($companies as $company) {
                    $previous = $company->status;
                    $target = $this->billingService->recalculateCompanyStatus($company, $today);
                    $processed++;

                    $key = match (true) {
                        $previous !== 'active' && $target === 'active' => 'past_due_cleared',
                        $previous === 'past_due' && $target === 'suspended' => 'past_due_to_suspended',
                        default => 'no_change',
                    };
                    $transitions[$key]++;
                }
            }, 'nit');

        $this->info("Empresas evaluadas: {$processed}");
        $this->info("- Reactivadas (→ active): {$transitions['past_due_cleared']}");
        $this->info("- Suspendidas (past_due → suspended): {$transitions['past_due_to_suspended']}");
        $this->info("- Sin cambio: {$transitions['no_change']}");

        return self::SUCCESS;
    }

    private function resolveDate(): CarbonInterface
    {
        $dateOption = $this->option('date');

        return $dateOption !== null && $dateOption !== ''
            ? Carbon::parse($dateOption)
            : Carbon::now();
    }
}
