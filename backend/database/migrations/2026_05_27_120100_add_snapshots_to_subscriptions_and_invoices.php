<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots de plan + desglose tributario.
 *
 * Razón: los planes pueden cambiar (precio, features, tarifa). Las suscripciones
 * vivas e invoices ya emitidas deben recordar el plan vigente al momento del
 * contrato/emisión. Auditoría DIAN exige que invoices reproduzcan exactamente
 * los montos al inspector años después.
 *
 * Cambios en `subscriptions`:
 *  - `plan_name_snapshot`, `plan_price_snapshot`, `plan_features_snapshot`,
 *    `plan_tax_regime_snapshot`, `plan_tax_rate_snapshot`, `plan_snapshot_at`.
 *  - `deleted_at` (SoftDeletes).
 *
 * Cambios en `invoices`:
 *  - `plan_name_snapshot`, `plan_price_snapshot`, `plan_snapshot_at` —
 *    qué plan generó esta factura.
 *  - `base_amount_taxable decimal(12,2)` — base gravable post-descuento (IVA aparte).
 *  - `tax_amount decimal(12,2)` — IVA del período.
 *  - `tax_rate decimal(5,2)` — tarifa snapshot.
 *  - `tax_regime varchar` — régimen snapshot.
 *  - `electronic_document_id uuid nullable FK` — vínculo a DIAN doc emitido.
 *
 * Backfill data: si hay subscriptions/invoices existentes, se copia del plan
 * vigente al momento de la migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('plan_name_snapshot', 100)->nullable()->after('billing_plan_id');
            $table->decimal('plan_price_snapshot', 12, 2)->nullable()->after('plan_name_snapshot');
            $table->jsonb('plan_features_snapshot')->nullable()->after('plan_price_snapshot');
            $table->string('plan_tax_regime_snapshot', 30)->nullable()->after('plan_features_snapshot');
            $table->decimal('plan_tax_rate_snapshot', 5, 2)->nullable()->after('plan_tax_regime_snapshot');
            $table->timestamp('plan_snapshot_at')->nullable()->after('plan_tax_rate_snapshot');
            $table->softDeletes();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('plan_name_snapshot', 100)->nullable()->after('subscription_id');
            $table->decimal('plan_price_snapshot', 12, 2)->nullable()->after('plan_name_snapshot');
            $table->timestamp('plan_snapshot_at')->nullable()->after('plan_price_snapshot');

            $table->decimal('base_amount_taxable', 12, 2)->nullable()->after('base_amount');
            $table->decimal('tax_amount', 12, 2)->nullable()->after('discount_amount');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_amount');
            $table->string('tax_regime', 30)->nullable()->after('tax_rate');

            $table->uuid('electronic_document_id')->nullable()->after('pdf_generated_at');
        });

        // Backfill: poblar snapshot en subscriptions activas con el plan vigente.
        // Best-effort — si los planes cambiaron entre la creación de la sub y
        // este momento, el snapshot ahora refleja el plan ACTUAL (drift histórico
        // inevitable hasta esta migration). Forward: BillingService llena los
        // snapshots al crear y al generar invoices.
        DB::table('subscriptions')
            ->whereNull('plan_snapshot_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $sub) {
                    $plan = DB::table('billing_plans')->where('id', $sub->billing_plan_id)->first();
                    if ($plan === null) {
                        continue;
                    }
                    DB::table('subscriptions')->where('id', $sub->id)->update([
                        'plan_name_snapshot' => $plan->name,
                        'plan_price_snapshot' => $plan->price,
                        'plan_features_snapshot' => $plan->features,
                        'plan_tax_regime_snapshot' => $plan->tax_regime ?? 'iva_19',
                        'plan_tax_rate_snapshot' => $plan->tax_rate ?? 19.00,
                        'plan_snapshot_at' => $sub->starts_at,
                    ]);
                }
            });

        // Backfill: invoices históricas — calcular base/tax desde amount asumiendo IVA 19%.
        // Solo si el monto es positivo (facturas no anuladas).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE invoices SET
                    plan_name_snapshot = COALESCE(plan_name_snapshot, (SELECT plan_name_snapshot FROM subscriptions s WHERE s.id = invoices.subscription_id)),
                    plan_price_snapshot = COALESCE(plan_price_snapshot, base_amount),
                    plan_snapshot_at = COALESCE(plan_snapshot_at, generated_at),
                    base_amount_taxable = COALESCE(base_amount_taxable, ROUND((COALESCE(amount, 0) / 1.19)::numeric, 2)),
                    tax_amount = COALESCE(tax_amount, ROUND((COALESCE(amount, 0) - (COALESCE(amount, 0) / 1.19))::numeric, 2)),
                    tax_rate = COALESCE(tax_rate, 19.00),
                    tax_regime = COALESCE(tax_regime, 'iva_19')
                WHERE plan_snapshot_at IS NULL
            SQL);
        }

        // FK al electronic_documents (ya consume esta columna).
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreign('electronic_document_id')
                ->references('id')->on('electronic_documents')
                ->nullOnDelete();
            $table->index('electronic_document_id', 'idx_invoices_electronic_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['electronic_document_id']);
            $table->dropIndex('idx_invoices_electronic_document_id');
            $table->dropColumn([
                'plan_name_snapshot',
                'plan_price_snapshot',
                'plan_snapshot_at',
                'base_amount_taxable',
                'tax_amount',
                'tax_rate',
                'tax_regime',
                'electronic_document_id',
            ]);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'plan_name_snapshot',
                'plan_price_snapshot',
                'plan_features_snapshot',
                'plan_tax_regime_snapshot',
                'plan_tax_rate_snapshot',
                'plan_snapshot_at',
            ]);
        });
    }
};
