<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingPlan;
use App\Models\Subscription;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time (#alza-precio): sube el `plan_price_snapshot` de las subscriptions
 * activas del plan default de $100.000 a $300.000 COP/mes.
 *
 * Las empresas nuevas ya nacen en 300k (precio catálogo, migración
 * 2026_07_01_120000). Este comando existe para las empresas YA activas, cuyo
 * invoice se genera del snapshot de su subscription — no del precio vivo.
 *
 * Ejecución: NO se agenda. Se corre a mano UNA sola vez el 2026-07-01 con
 * `php artisan billing:apply-plan-price-hike`. Idempotente: solo toca snapshots
 * que aún llevan OLD_PRICE, así que un segundo disparo es no-op. El bloque de
 * schedule queda comentado en routes/console.php por trazabilidad (no se borra).
 *
 * Consecuencia post-pago de correrlo el 2026-07-01: el próximo invoice (1-ago,
 * factura julio) ya sale a $300.000 para las empresas existentes.
 *
 * ponytail: alza puntual — el comando se conserva por trazabilidad; queda
 * dormido (no-op) una vez que ninguna subscription siga en $100.000.
 */
class ApplyPlanPriceHikeCommand extends Command
{
    private const OLD_PRICE = 100000.00;

    private const NEW_PRICE = 300000.00;

    protected $signature = 'billing:apply-plan-price-hike {--dry-run : Solo reporta, sin escribir}';

    protected $description = 'Sube el snapshot de subscriptions activas del plan default a $300.000 (alza para empresas existentes)';

    public function handle(AuditService $auditService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $defaultPlan = BillingPlan::default();
        if ($defaultPlan === null) {
            $this->error('No hay un plan default configurado (is_default=true). Aborta.');

            return self::FAILURE;
        }

        $pending = Subscription::query()
            ->where('billing_plan_id', $defaultPlan->id)
            ->where('status', 'active')
            ->where('plan_price_snapshot', self::OLD_PRICE)
            ->get(['id', 'company_nit']);

        if ($pending->isEmpty()) {
            $this->info('No hay subscriptions activas en $'.number_format(self::OLD_PRICE).' — nada que hacer.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'DRY-RUN: ' : '').'Subiendo '.$pending->count()." subscription(s) de \${$this->fmt(self::OLD_PRICE)} a \${$this->fmt(self::NEW_PRICE)}...");

        $updated = 0;

        foreach ($pending as $sub) {
            if ($dryRun) {
                $this->line("  [dry-run] Subscription {$sub->id} (empresa {$sub->company_nit})");
                $updated++;

                continue;
            }

            DB::transaction(function () use ($sub, $auditService, &$updated): void {
                // lockForUpdate serializa contra el cron de facturación / otra
                // instancia EC2: re-lee y salta si ya fue migrada.
                $fresh = Subscription::query()->where('id', $sub->id)->lockForUpdate()->first();

                if ($fresh === null || (float) $fresh->plan_price_snapshot !== self::OLD_PRICE) {
                    return;
                }

                $fresh->forceFill(['plan_price_snapshot' => self::NEW_PRICE])->save();

                $auditService->log(
                    action: 'subscription.reprice',
                    auditable: $fresh,
                    data: [
                        'company_nit' => $fresh->company_nit,
                        'from' => self::OLD_PRICE,
                        'to' => self::NEW_PRICE,
                        'reason' => 'plan_price_hike_2026',
                    ],
                );

                $updated++;
            });
        }

        $this->info("Listo: {$updated} subscription(s) actualizada(s).");

        return self::SUCCESS;
    }

    private function fmt(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
