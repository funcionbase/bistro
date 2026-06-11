<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Backfill #246 — asigna el plan default vigente a empresas y subscriptions
 * sin snapshot.
 *
 * Casos cubiertos (idempotente):
 *  1. Company activa sin Subscription activa: crea Subscription al plan
 *     default con `starts_at = company.created_at` (retroactivo, decisión #7).
 *  2. Subscription sin snapshot del plan: copia los campos `plan_*_snapshot`
 *     desde el plan vigente al momento del comando.
 *
 * Sin invoices retroactivos. El próximo `generate_day` factura desde el período
 * vigente.
 *
 * Uso:
 *   php artisan billing:backfill-default-plan --dry-run     # solo reporta
 *   php artisan billing:backfill-default-plan --force       # aplica cambios
 */
class BackfillDefaultPlanCommand extends Command
{
    protected $signature = 'billing:backfill-default-plan
                            {--dry-run : Solo reporta lo que haría, sin escribir}
                            {--force : Confirma ejecución en pdn sin prompt}';

    protected $description = 'Backfill: asigna plan default + snapshot a empresas y subscriptions sin él';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $defaultPlan = BillingPlan::default();
        if ($defaultPlan === null) {
            $this->error('No hay un plan default configurado (is_default=true). Aborta.');

            return self::FAILURE;
        }

        $env = (string) app()->environment();
        if ($env === 'production' && ! $dryRun && ! $this->option('force')) {
            if (! $this->confirm('Estás en PDN. ¿Confirmas el backfill?', false)) {
                $this->warn('Cancelado por el operador.');

                return self::FAILURE;
            }
        }

        $this->info("Backfill default plan — plan: {$defaultPlan->name} (slug={$defaultPlan->slug}, price={$defaultPlan->price})");
        $this->info($dryRun ? 'DRY-RUN: no se escribirán cambios.' : 'Aplicando cambios...');

        $createdSubs = 0;
        $backfilledSnapshots = 0;

        // 1. Empresas activas sin subscription activa → crear Subscription al plan default.
        Company::query()
            ->whereIn('status', ['active', 'past_due', 'suspended'])
            ->whereDoesntHave('subscriptions', fn ($q) => $q->where('status', 'active'))
            ->chunkById(200, function ($companies) use ($defaultPlan, $dryRun, &$createdSubs): void {
                foreach ($companies as $company) {
                    $startsAt = $company->created_at ?? now();

                    if ($dryRun) {
                        $this->line("  [dry-run] Company {$company->nit} → nueva Subscription default, starts_at={$startsAt->toDateString()}");
                        $createdSubs++;

                        continue;
                    }

                    DB::transaction(function () use ($company, $defaultPlan, $startsAt, &$createdSubs): void {
                        // Defensa: el UNIQUE parcial bloquea race conditions.
                        $exists = Subscription::query()
                            ->where('company_nit', $company->nit)
                            ->where('status', 'active')
                            ->lockForUpdate()
                            ->exists();
                        if ($exists) {
                            return;
                        }

                        Subscription::query()->create([
                            'company_nit' => $company->nit,
                            'billing_plan_id' => $defaultPlan->id,
                            'plan_name_snapshot' => $defaultPlan->name,
                            'plan_price_snapshot' => $defaultPlan->price,
                            'plan_features_snapshot' => $defaultPlan->features ?? [],
                            'plan_tax_regime_snapshot' => $defaultPlan->tax_regime,
                            'plan_tax_rate_snapshot' => $defaultPlan->tax_rate,
                            'plan_snapshot_at' => Carbon::parse($startsAt),
                            'status' => 'active',
                            'starts_at' => Carbon::parse($startsAt)->toDateString(),
                        ]);
                        $createdSubs++;
                    });
                }
            });

        // 2. Subscriptions sin snapshot → copiar del plan vigente (best-effort).
        Subscription::query()
            ->whereNull('plan_snapshot_at')
            ->with('plan')
            ->chunkById(200, function ($subs) use ($dryRun, &$backfilledSnapshots): void {
                foreach ($subs as $sub) {
                    $plan = $sub->plan;
                    if ($plan === null) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("  [dry-run] Subscription {$sub->id} → backfill snapshot del plan {$plan->slug}");
                        $backfilledSnapshots++;

                        continue;
                    }

                    $sub->forceFill([
                        'plan_name_snapshot' => $plan->name,
                        'plan_price_snapshot' => $plan->price,
                        'plan_features_snapshot' => $plan->features ?? [],
                        'plan_tax_regime_snapshot' => $plan->tax_regime ?? 'iva_19',
                        'plan_tax_rate_snapshot' => $plan->tax_rate ?? 19.00,
                        'plan_snapshot_at' => $sub->starts_at ?? $sub->created_at ?? now(),
                    ])->save();
                    $backfilledSnapshots++;
                }
            });

        $this->newLine();
        $this->info('Resumen:');
        $this->line("  Subscriptions creadas:     {$createdSubs}");
        $this->line("  Snapshots backfilled:      {$backfilledSnapshots}");

        return self::SUCCESS;
    }
}
