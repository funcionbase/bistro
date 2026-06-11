<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 09 — Coupons + Deliveries.
 *
 * coupons:
 *  - type ∈ {percentage, fixed_amount}. value en decimal(12,2) — para fixed_amount
 *    es un monto en COP; para percentage es un % de 0..100 (decimal por flexibilidad).
 *  - min_order_amount en decimal(12,2). Uniformado desde (10,2).
 *  - scope ∈ {branch, company}. branch = sólo válido en sede creadora. company =
 *    aplica a `valid_in_branches[]` o todas si NULL.
 *  - SoftDeletes: nunca borrar cupones — preservar histórico de canjes.
 *
 * coupon_redemptions:
 *  - Append-only. Un canje deja registrado discount_amount, order_total_before/after.
 *  - Todos los montos a decimal(12,2) — uniformado desde (10,2).
 *
 * deliveries:
 *  - Pivot order ↔ deliverer. UNIQUE PARCIAL: una sola entrega 'pending' activa
 *    por orden (`status='pending' AND deleted_at IS NULL`). Permite reasignar
 *    creando filas adicionales cuando una se cancela.
 *  - SoftDeletes para preservar historial de reasignación.
 *
 * branch_id NOT NULL en todas las tablas desde el inicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('scope', 16)->default('branch');
            $table->json('valid_in_branches')->nullable();
            $table->string('code', 32);
            $table->enum('type', ['percentage', 'fixed_amount']);
            $table->decimal('value', 12, 2);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            // Programación por franja horaria (happy hour). TZ Bogotá implícita.
            $table->jsonb('valid_days')->nullable();
            $table->time('valid_hours_from')->nullable();
            $table->time('valid_hours_to')->nullable();
            $table->boolean('auto_apply')->default(false);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->decimal('min_order_amount', 12, 2)->default(0);
            $table->boolean('first_order_only')->default(false);
            // Cupones de canje de fidelización.
            $table->boolean('is_single_use')->default(false);
            $table->string('locked_to_phone', 30)->nullable();
            $table->string('source', 20)->default('manual');
            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('active');
            $table->string('created_by', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique(['company_nit', 'code']);
            $table->index(['company_nit', 'status', 'valid_until'], 'coupons_company_nit_status_valid_until_index');
            $table->index(['company_nit', 'branch_id'], 'coupons_company_branch_idx');
            $table->index(['company_nit', 'scope'], 'coupons_company_scope_idx');
            $table->index(['company_nit', 'locked_to_phone'], 'coupons_company_locked_phone_idx');
            $table->index(['company_nit', 'source'], 'coupons_company_source_idx');
        });
        // Horario de validez: ambas columnas deben llenarse juntas o ambas null.
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_valid_hours_pair_chk
            CHECK ((valid_hours_from IS NULL AND valid_hours_to IS NULL)
                OR (valid_hours_from IS NOT NULL AND valid_hours_to IS NOT NULL))');
        DB::statement("CREATE INDEX coupons_auto_apply_active_idx
            ON coupons (company_nit) WHERE auto_apply = true AND status = 'active' AND deleted_at IS NULL");

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('client_phone', 30)->nullable();
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('order_total_before', 12, 2)->default(0);
            $table->decimal('order_total_after', 12, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['coupon_id', 'created_at']);
            $table->index('company_nit', 'coupon_redemptions_company_nit_index');
            $table->index(['company_nit', 'branch_id'], 'coupon_redemptions_company_branch_idx');
        });
        DB::statement('CREATE INDEX idx_coupon_redemptions_company_today
            ON coupon_redemptions (company_nit, created_at DESC)');

        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit', 20);
            $table->uuid('branch_id');
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('status')->default('pending');
            $table->uuid('previous_delivery_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index('company_nit');
            $table->index(['company_nit', 'user_id', 'assigned_at']);
            $table->index('status');
            $table->index('assigned_at');
            $table->index('delivered_at');
            $table->index(['company_nit', 'branch_id'], 'deliveries_company_branch_idx');
        });
        // Self-reference FK aplicado fuera del create() — ver invoices block para contexto.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreign('previous_delivery_id')->references('id')->on('deliveries')->nullOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX deliveries_active_order_unique
            ON deliveries (order_id, company_nit)
            WHERE status = \'pending\' AND deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_deliveries_company_active
            ON deliveries (company_nit, status, assigned_at DESC)
            WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
