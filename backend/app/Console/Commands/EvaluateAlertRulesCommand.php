<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Alerts\AlertEngine;
use Illuminate\Console\Command;

/**
 * Evalúa diariamente las reglas de alerta y crea/actualiza eventos.
 *
 * Para cada empresa (o la indicada con --company), dispara AlertEngine que
 * recorre AlertRule habilitadas y persiste drafts con dedup diario. Diseñado
 * para correrse 1x/día desde routes/console.php; correrlo más seguido no
 * duplica eventos (el índice UNIQUE PARCIAL por DATE(triggered_at) lo evita).
 */
class EvaluateAlertRulesCommand extends Command
{
    protected $signature = 'alerts:evaluate
                            {--company= : NIT específico (default: todas las empresas)}';

    protected $description = 'Evalúa reglas de alerta y crea eventos accionables';

    public function __construct(private readonly AlertEngine $engine)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $companyNit = $this->option('company');

        if ($companyNit) {
            $exists = Company::where('nit', $companyNit)->exists();
            if (! $exists) {
                $this->error("Empresa {$companyNit} no encontrada.");

                return self::FAILURE;
            }

            $stats = $this->engine->runForCompany($companyNit);
            $this->info("→ {$companyNit} drafts={$stats['drafts']} persisted={$stats['persisted']}");

            return self::SUCCESS;
        }

        $stats = $this->engine->runAll();
        $this->info(
            "DONE companies={$stats['companies']} drafts={$stats['drafts']} persisted={$stats['persisted']}"
        );

        return self::SUCCESS;
    }
}
