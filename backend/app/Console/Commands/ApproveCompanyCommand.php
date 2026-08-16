<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\BillingService;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Comando interno para aprobar el registro de una empresa.
 *
 * Operacion administrativa: transiciona la empresa de `pending_activation`
 * a `active`, asegura una Subscription activa con snapshot del plan,
 * registra en audit_logs y dispara el correo de bienvenida a owners+admins.
 *
 * Uso:
 *   php artisan companies:approve 900123456 --plan=default --notes="Verificado ticket #ABC"
 *
 * Argumentos:
 *   nit       NIT de la empresa a activar.
 *
 * Opciones:
 *   --plan=   Slug del plan a asignar. Si se omite, se usa BillingPlan::default().
 *             Si la empresa ya tiene subscription activa con plan distinto, falla.
 *   --notes=  Texto libre auditable (ticket, motivo). Queda en audit_logs.data.notes.
 *
 * Salida:
 *   0 — exito.
 *   1 — DomainException (estado invalido, plan inexistente, conflicto subscription).
 *   2 — error tecnico (BD, throwable inesperado).
 */
class ApproveCompanyCommand extends Command
{
    protected $signature = 'companies:approve
        {nit : NIT de la empresa a activar}
        {--plan= : Slug del plan a asignar (default: BillingPlan::default())}
        {--notes= : Texto libre auditable}';

    protected $description = 'Aprueba el registro de una empresa pending_activation y dispara el correo de bienvenida.';

    public function handle(BillingService $billingService): int
    {
        $nit = (string) $this->argument('nit');
        $planSlug = $this->option('plan');
        $notes = $this->option('notes');

        $company = Company::query()->where('nit', $nit)->first();

        if ($company === null) {
            $this->error("No existe empresa con NIT {$nit}.");

            return self::FAILURE;
        }

        if ($company->status !== 'pending_activation') {
            $this->error(
                "La empresa {$nit} esta en estado '{$company->status}'. ".
                'Solo se puede aprobar desde pending_activation.'
            );

            return self::FAILURE;
        }

        try {
            $subscription = $billingService->activateCompany(
                company: $company,
                planSlug: $planSlug,
                notes: $notes,
            );
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Error tecnico al activar la empresa: '.$e->getMessage());

            return 2;
        }

        $this->info("Empresa {$nit} ({$company->commercial_name}) activada.");
        $this->line("Subscription: {$subscription->id} — plan '{$subscription->plan_name_snapshot}' "
            .'($'.number_format((float) $subscription->plan_price_snapshot, 0, ',', '.').' '
            .'COP).');
        $this->line('Correo de bienvenida encolado a owners+admins activos.');

        return self::SUCCESS;
    }
}
