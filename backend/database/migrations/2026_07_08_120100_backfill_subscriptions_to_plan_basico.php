<?php

use App\Models\BillingPlan;
use App\Models\Subscription;
use App\Services\AuditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill 2026-07: alinea el snapshot de las subscriptions activas del plan
 * default con el nuevo catálogo — "Plan Básico" a $0 COP/mes.
 *
 * Las invoices se generan del snapshot (`plan_price_snapshot`), no del precio
 * vivo del catálogo (drift inmune); sin este backfill las empresas
 * existentes seguirían facturando $300.000. Con precio $0 el generador de
 * invoices las salta (guard en BillingService::generateMonthlyInvoices).
 *
 * Idempotente: solo toca snapshots con precio > 0. Audita cada reprice
 * (patrón `billing:apply-plan-price-hike`). Invoices ya emitidas son
 * inmutables y no se modifican. No reversible por migración — el estado
 * previo queda reconstruible desde audit_logs (`subscription.reprice`).
 *
 * Requiere que corra antes `split_default_plan_into_basico_and_plus`
 * (garantizado por orden de timestamps).
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaultPlan = BillingPlan::default();

        if ($defaultPlan === null) {
            // Entorno sin catálogo sembrado (fresh sin seeders previos): la
            // migración anterior lo siembra, así que esto es defensivo puro.
            return;
        }

        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        // Incluye snapshots NULL (subs legacy): sin snapshot el
        // invoice caería al precio vivo del catálogo — mejor sellarlas acá.
        $pending = Subscription::query()
            ->where('billing_plan_id', $defaultPlan->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('plan_price_snapshot')->orWhere('plan_price_snapshot', '>', 0))
            ->pluck('id');

        foreach ($pending as $subscriptionId) {
            DB::transaction(function () use ($subscriptionId, $defaultPlan, $auditService): void {
                // lockForUpdate serializa contra el cron de facturación / otra
                // instancia EC2: re-lee y salta si ya fue migrada.
                $fresh = Subscription::query()
                    ->where('id', $subscriptionId)
                    ->lockForUpdate()
                    ->first();

                if ($fresh === null || ($fresh->plan_price_snapshot !== null && (float) $fresh->plan_price_snapshot <= 0.0)) {
                    return;
                }

                $previousPrice = $fresh->plan_price_snapshot !== null ? (float) $fresh->plan_price_snapshot : null;
                $previousName = $fresh->plan_name_snapshot;

                $fresh->forceFill([
                    'plan_name_snapshot' => $defaultPlan->name,
                    'plan_price_snapshot' => $defaultPlan->price,
                    'plan_features_snapshot' => $defaultPlan->features,
                    'plan_snapshot_at' => now(),
                ])->save();

                $auditService->log(
                    action: 'subscription.reprice',
                    auditable: $fresh,
                    data: [
                        'company_nit' => $fresh->company_nit,
                        'from' => $previousPrice,
                        'to' => (float) $defaultPlan->price,
                        'from_name' => $previousName,
                        'to_name' => $defaultPlan->name,
                        'reason' => 'plan_basico_gratis_2026_07',
                    ],
                );
            });
        }
    }

    public function down(): void
    {
        // Backfill financiero: no reversible por migración. El estado previo
        // queda reconstruible desde audit_logs (subscription.reprice).
    }
};
