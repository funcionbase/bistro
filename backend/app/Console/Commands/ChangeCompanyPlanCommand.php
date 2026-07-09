<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el plan de facturación de una empresa (operación de backoffice) —
 * invocado desde GitHub Action `bistro-ops-company-plan.yml`.
 *
 * Modelo 2026-07: dos planes — `default` (Plan Básico, $0) y `plus` (Plan
 * Plus, $300.000/mes + $10 por factura electrónica). Este comando mueve una
 * empresa entre planes:
 *
 *  1. Cancela la subscription activa actual (status=cancelled, ends_at hoy) —
 *     no se muta el snapshot histórico, queda como registro contable.
 *  2. Crea una subscription nueva `active` con snapshot completo del plan
 *     destino. El próximo ciclo de facturación (post-pago, día 1) ya factura
 *     con el snapshot nuevo.
 *
 * El cambio aplica desde el período corriente: el mes en curso se facturará
 * completo al precio del plan destino (sin prorrateo — decisión simple, el
 * cambio se opera típicamente a inicio de mes).
 *
 * Idempotente: si la empresa ya está en el plan destino, no-op con SUCCESS.
 * Audita `subscription.plan_changed` con metadata reconstructible.
 *
 * Uso:
 *   php artisan billing:change-plan --nit=900123456 --plan=plus
 *   php artisan billing:change-plan --nit=900123456 --plan=default --dry-run
 */
class ChangeCompanyPlanCommand extends Command
{
    protected $signature = 'billing:change-plan
                            {--nit= : NIT de la empresa (sin DV)}
                            {--plan= : Slug del plan destino (default|plus)}
                            {--dry-run : Solo reporta, sin escribir}';

    protected $description = 'Cambia el plan de facturación de una empresa por NIT (operación backoffice)';

    public function __construct(private readonly AuditService $auditService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $nit = trim((string) $this->option('nit'));
        $planSlug = strtolower(trim((string) $this->option('plan')));
        $dryRun = (bool) $this->option('dry-run');

        if ($nit === '' || $planSlug === '') {
            $this->error('--nit y --plan son obligatorios.');

            return self::FAILURE;
        }

        $company = Company::query()->where('nit', $nit)->first();
        if ($company === null) {
            $this->error("Empresa NIT={$nit} no existe.");

            return self::FAILURE;
        }

        $targetPlan = BillingPlan::query()
            ->where('slug', $planSlug)
            ->where('is_active', true)
            ->first();
        if ($targetPlan === null) {
            $this->error("No existe plan activo con slug '{$planSlug}'.");

            return self::FAILURE;
        }

        $current = Subscription::query()
            ->with('plan')
            ->where('company_nit', $company->nit)
            ->where('status', 'active')
            ->first();

        if ($current !== null && $current->billing_plan_id === $targetPlan->id) {
            $this->info("No-op: NIT={$nit} ya está en el plan '{$targetPlan->name}' ({$planSlug}).");

            return self::SUCCESS;
        }

        $fromLabel = $current !== null
            ? "{$current->plan_name_snapshot} (\${$this->fmt((float) $current->plan_price_snapshot)})"
            : 'sin subscription activa';

        if ($dryRun) {
            $this->info("DRY-RUN: NIT={$nit} pasaría de {$fromLabel} a '{$targetPlan->name}' (\${$this->fmt((float) $targetPlan->price)}/mes).");

            return self::SUCCESS;
        }

        $created = DB::transaction(function () use ($company, $targetPlan, $current): Subscription {
            if ($current !== null) {
                // lockForUpdate serializa contra el cron de facturación / otra
                // instancia EC2; re-verifica que siga activa antes de cancelar.
                $freshCurrent = Subscription::query()
                    ->where('id', $current->id)
                    ->lockForUpdate()
                    ->first();

                if ($freshCurrent !== null && $freshCurrent->status === 'active') {
                    $freshCurrent->forceFill([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'ends_at' => now()->toDateString(),
                    ])->save();
                }
            }

            $new = Subscription::create([
                'company_nit' => $company->nit,
                'billing_plan_id' => $targetPlan->id,
                'status' => 'active',
                'starts_at' => now()->toDateString(),
                'plan_name_snapshot' => $targetPlan->name,
                'plan_price_snapshot' => $targetPlan->price,
                'plan_features_snapshot' => $targetPlan->features,
                'plan_tax_regime_snapshot' => $targetPlan->tax_regime,
                'plan_tax_rate_snapshot' => $targetPlan->tax_rate,
                'plan_snapshot_at' => now(),
            ]);

            $this->auditService->log(
                action: 'subscription.plan_changed',
                auditable: $new,
                data: [
                    'company_nit' => $company->nit,
                    'from_subscription_id' => $current?->id,
                    'from_plan' => $current?->plan_name_snapshot,
                    'from_price' => $current !== null ? (float) $current->plan_price_snapshot : null,
                    'to_plan' => $targetPlan->name,
                    'to_price' => (float) $targetPlan->price,
                    'changed_via' => 'artisan_command',
                ],
            );

            return $new;
        });

        $this->info("OK NIT={$nit}: {$fromLabel} → '{$targetPlan->name}' (\${$this->fmt((float) $targetPlan->price)}/mes).");
        $this->line("  subscription nueva: {$created->id}");
        $this->line("  starts_at: {$created->starts_at?->toDateString()}");

        return self::SUCCESS;
    }

    private function fmt(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
