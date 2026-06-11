<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 04 — Billing (suscripciones SaaS internas).
 *
 * Modelo de facturación de la plataforma a empresas inquilinas:
 *  - billing_plans: catálogo de planes (free, basic, pro). currency=COP por defecto.
 *  - subscription_discounts: descuentos % por empresa con vigencia (starts_at..ends_at).
 *    UNIQUE parcial: solo 1 active por empresa+período.
 *  - subscriptions: 1 suscripción activa por empresa (UNIQUE parcial WHERE status='active').
 *  - invoices: facturas mensuales con period_from..period_to, voided_by_invoice_id (anulación
 *    por nota crédito = otra factura).
 *  - invoice_lines: items facturados (description, qty, unit_price, subtotal).
 *  - invoice_payments: pagos registrados contra facturas (con payment_reference obligatoria).
 *
 * Reglas contables:
 *  - Todos los montos en decimal(12,2) — COP, 2 decimales.
 *  - discount_percent/tax_rate en decimal(5,2) — 0..100.
 *  - voided_by_invoice_id permite trazar la nota crédito (no se hace UPDATE para anular).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('COP');
            $table->string('billing_cycle', 10)->default('monthly');
            $table->jsonb('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscription_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit')->index();
            $table->decimal('discount_percent', 5, 2);
            $table->string('description', 255);
            $table->date('starts_at');
            $table->smallInteger('months_duration')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE subscription_discounts ADD CONSTRAINT chk_discount_percent CHECK (discount_percent > 0 AND discount_percent <= 100)');
        DB::statement("CREATE INDEX idx_discounts_company_active ON subscription_discounts (company_nit, starts_at, ends_at) WHERE status = 'active'");

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit')->index();
            $table->foreignUuid('billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->restrictOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX uq_active_subscription_per_company ON subscriptions (company_nit) WHERE status = 'active'");

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit')->index();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->string('type', 20)->default('monthly');
            $table->date('period_from');
            $table->date('period_to');
            $table->smallInteger('days_billed');
            $table->decimal('base_amount', 12, 2);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('COP');
            $table->date('due_date');
            $table->timestamp('generated_at')->useCurrent();
            $table->string('status', 20)->default('pending');
            $table->uuid('voided_by_invoice_id')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->restrictOnDelete();
        });
        // Self-reference FK aplicado después del create para evitar el error
        // "no unique constraint matching given keys" que Postgres reporta cuando
        // un foreignUuid('xxx')->constrained() se ejecuta sobre la misma tabla
        // en el mismo statement antes de que el PRIMARY KEY constraint se haya
        // materializado.
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('voided_by_invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX uq_invoice_subscription_period ON invoices (subscription_id, period_from, period_to) WHERE status != 'voided'");

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description', 255);
            $table->smallInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('company_nit')->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('COP');
            $table->string('payment_reference', 150);
            $table->date('payment_date');
            $table->string('payment_method', 50)->nullable();
            $table->foreignUuid('registered_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_discounts');
        Schema::dropIfExists('billing_plans');
    }
};
