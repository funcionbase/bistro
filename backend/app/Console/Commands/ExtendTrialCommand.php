<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AuditService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Extiende el trial gratuito de una empresa a N meses contados desde su
 * `created_at`.
 *
 * Setea `companies.paid_billing_starts_at = created_at + N meses`. A partir
 * de esa fecha, `BillingService::generateMonthlyInvoices()` empieza a
 * generar facturas y `recalculateCompanyStatus()` la trata como facturable.
 *
 * Antes de la fecha: trial extendido, ninguna factura se genera y el status
 * se mantiene `active` aunque no haya pagos.
 *
 * Idempotente: si la fecha calculada coincide con la actual, no se muta.
 * Trazable: cada cambio queda en `audit_logs` como `company.trial_extended`
 * con `from`, `to`, `months`, `reason`, `actor`.
 *
 * Triggers típicos:
 *  - Workflow ops `.github/workflows/company-trial.yml` (auditable en
 *    Actions + audit_logs).
 *  - Ejecución manual por un operador con acceso al EC2 (consola).
 *
 * Ejemplo: `php artisan billing:extend-trial --nit=9009009001 --months=8 --reason="Convenio comercial Q3 2026"`
 */
class ExtendTrialCommand extends Command
{
    /** @var string */
    protected $signature = 'billing:extend-trial
        {--nit= : NIT de la empresa (string)}
        {--months= : Meses de trial gratuito contados desde created_at (1-24)}
        {--reason= : Motivo del cambio (5-500 chars, queda en audit_logs)}
        {--actor= : Quién autoriza (string corto). Default: cli}
        {--dry-run : Imprime el cambio sin escribir}';

    /** @var string */
    protected $description = 'Otorga N meses gratuitos a una empresa antes de comenzar a facturar.';

    public function __construct(
        private readonly BillingService $billingService,
        private readonly AuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $nit = (string) $this->option('nit');
        $monthsRaw = $this->option('months');
        $reason = trim((string) $this->option('reason'));
        $actor = trim((string) ($this->option('actor') ?? '')) ?: 'cli';
        $dryRun = (bool) $this->option('dry-run');

        if ($nit === '') {
            $this->error('--nit es requerido.');

            return self::INVALID;
        }

        $months = (int) $monthsRaw;
        if ($months < 1 || $months > 24) {
            $this->error('--months debe estar entre 1 y 24.');

            return self::INVALID;
        }

        $reasonLen = mb_strlen($reason);
        if ($reasonLen < 5 || $reasonLen > 500) {
            $this->error('--reason debe tener entre 5 y 500 caracteres (recibido: '.$reasonLen.').');

            return self::INVALID;
        }

        $today = Carbon::now()->startOfDay();

        $result = DB::transaction(function () use ($nit, $months, $reason, $actor, $dryRun, $today) {
            /** @var Company|null $company */
            $company = Company::query()->where('nit', $nit)->lockForUpdate()->first();

            if ($company === null) {
                return ['outcome' => 'not_found', 'company' => null, 'from' => null, 'to' => null];
            }

            $createdAt = $company->created_at?->copy()?->startOfDay();
            if ($createdAt === null) {
                return ['outcome' => 'no_created_at', 'company' => $company, 'from' => null, 'to' => null];
            }

            $previous = $company->paid_billing_starts_at?->toDateString();
            $target = $createdAt->copy()->addMonthsNoOverflow($months)->toDateString();

            if ($previous === $target) {
                return ['outcome' => 'no_op', 'company' => $company, 'from' => $previous, 'to' => $target];
            }

            if ($dryRun) {
                return ['outcome' => 'dry_run', 'company' => $company, 'from' => $previous, 'to' => $target];
            }

            $company->paid_billing_starts_at = $target;
            $company->save();

            $this->auditService->log('company.trial_extended', null, $company, [
                'company_nit' => $company->nit,
                'from' => $previous,
                'to' => $target,
                'months' => $months,
                'reason' => $reason,
                'actor' => $actor,
                'source' => 'artisan',
            ]);

            // Recalcular status por si la empresa estaba en past_due/suspended
            // por error administrativo: con el trial extendido vuelve a active.
            $this->billingService->recalculateCompanyStatus($company, $today);

            return ['outcome' => 'success', 'company' => $company, 'from' => $previous, 'to' => $target];
        });

        return $this->reportOutcome($result, $nit, $months, $dryRun);
    }

    /**
     * @param  array{outcome: string, company: Company|null, from: ?string, to: ?string}  $result
     */
    private function reportOutcome(array $result, string $nit, int $months, bool $dryRun): int
    {
        return match ($result['outcome']) {
            'not_found' => tap(self::FAILURE, fn () => $this->error("Empresa no encontrada para NIT={$nit}.")),
            'no_created_at' => tap(self::FAILURE, fn () => $this->error("La empresa {$nit} no tiene created_at; no se puede extender el trial.")),
            'no_op' => tap(self::SUCCESS, fn () => $this->info("no-op: {$nit} ya tiene paid_billing_starts_at = {$result['to']} (= created_at + {$months} meses).")),
            'dry_run' => tap(self::SUCCESS, fn () => $this->line("DRY-RUN: {$nit} pasaría de paid_billing_starts_at={$result['from']} a {$result['to']} (created_at + {$months} meses).")),
            'success' => tap(self::SUCCESS, function () use ($result, $nit, $months) {
                $this->info("OK: {$nit} ahora factura desde {$result['to']} (created_at + {$months} meses).");
                if ($result['from'] !== null) {
                    $this->line("Antes: {$result['from']}.");
                }
                $this->line('Audit log registrado como company.trial_extended.');
            }),
            default => self::FAILURE,
        };
    }
}
