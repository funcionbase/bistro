<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #246 Fase 1 — Aplicación de promo codes a empresas (`company_promo_codes`).
 *
 * Tabla puente entre `promo_codes` (catálogo) y `companies`. Cada fila representa
 * un descuento vigente o histórico aplicado a una empresa específica.
 *
 * Snapshot inmutable: `discount_percent` y `months_duration` se congelan al
 * aplicar. Si el `promo_codes` original cambia o se desactiva, la aplicación
 * histórica conserva los términos originales — auditoría contable DIAN.
 *
 * `starts_at` se calcula según vector:
 *  - enrollment: `companies.created_at` (registro)
 *  - github_action: primer día del próximo mes
 *  - self_service: primer día del próximo mes
 *  - Si hay `companies.paid_billing_starts_at > now()` (trial activo):
 *    se difiere a `paid_billing_starts_at` (decisión #246 #3).
 *
 * Constraint UNIQUE parcial: solo 1 promo `status='active'` por empresa a la vez.
 *
 * Soft-deleted (política #246 §9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_promo_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->foreignUuid('promo_code_id')->constrained('promo_codes')->restrictOnDelete();
            $table->smallInteger('discount_percent');
            $table->smallInteger('months_duration');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('active');
            $table->string('applied_via', 20);
            $table->foreignUuid('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_nit')->references('nit')->on('companies')->restrictOnDelete();
            $table->index(['company_nit', 'status']);
            $table->index(['ends_at', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE company_promo_codes ADD CONSTRAINT chk_company_promo_discount_percent CHECK (discount_percent BETWEEN 1 AND 100)');
            DB::statement('ALTER TABLE company_promo_codes ADD CONSTRAINT chk_company_promo_months_duration CHECK (months_duration BETWEEN 1 AND 120)');
            DB::statement("ALTER TABLE company_promo_codes ADD CONSTRAINT chk_company_promo_status CHECK (status IN ('active', 'expired', 'cancelled'))");
            DB::statement("ALTER TABLE company_promo_codes ADD CONSTRAINT chk_company_promo_applied_via CHECK (applied_via IN ('enrollment', 'github_action', 'self_service'))");
            DB::statement('ALTER TABLE company_promo_codes ADD CONSTRAINT chk_company_promo_dates CHECK (starts_at < ends_at)');

            // UNIQUE parcial: solo 1 promo activo por empresa.
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX uq_company_promo_active_per_company
                ON company_promo_codes (company_nit)
                WHERE status = 'active'
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_promo_codes');
    }
};
