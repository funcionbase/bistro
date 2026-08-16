<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #246 Fase 1 — Plan default + impuestos en `billing_plans`.
 *
 * Cambios:
 *  - `is_default boolean default false` con UNIQUE WHERE is_default=true.
 *    Solo 1 plan puede ser el "Default 100k" — referido por enrollment y
 *    backfill de empresas existentes.
 *  - `price_includes_tax boolean default true` — el `price` mostrado al
 *    cliente es bruto (incluye IVA). Backend extrae base con `Money::extractBase`.
 *  - `tax_regime varchar default 'iva_19'` — régimen tributario del plan.
 *  - `tax_rate decimal(5,2) default 19.00` — tarifa congelada por plan.
 *
 * Régimen funcionbase confirmado en #246 decisión #2: IVA 19% (Régimen común).
 * Configurable globalmente vía `config('billing.funcionbase_tax_regime')`,
 * pero la fila del plan congela el valor histórico para auditoría DIAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->boolean('price_includes_tax')->default(true)->after('is_default');
            $table->string('tax_regime', 30)->default('iva_19')->after('price_includes_tax');
            $table->decimal('tax_rate', 5, 2)->default(19.00)->after('tax_regime');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // UNIQUE parcial: solo un plan puede tener is_default=true a la vez.
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX billing_plans_is_default_unique
                ON billing_plans ((1))
                WHERE is_default = true
            SQL);

            // CHECK constraint: tax_rate ∈ [0, 100].
            DB::statement('ALTER TABLE billing_plans ADD CONSTRAINT chk_billing_plans_tax_rate CHECK (tax_rate >= 0 AND tax_rate <= 100)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE billing_plans DROP CONSTRAINT IF EXISTS chk_billing_plans_tax_rate');
            DB::statement('DROP INDEX IF EXISTS billing_plans_is_default_unique');
        }

        Schema::table('billing_plans', function (Blueprint $table): void {
            $table->dropColumn(['is_default', 'price_includes_tax', 'tax_regime', 'tax_rate']);
        });
    }
};
