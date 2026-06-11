<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programa de fidelización con puntos.
 *
 *  - loyalty_accounts: una fila por (company_nit, client_phone). Cross-sede:
 *    el cliente acumula y canjea puntos en cualquier sede de la empresa.
 *    balance/lifetime_earned en integer (los puntos NO son moneda — CLAUDE.md).
 *
 *  - loyalty_movements: append-only. type ∈ {earn, redeem, refund_reverse,
 *    adjust, expire}. points es signed (earn positivos, redeem/expire/refund
 *    negativos, adjust cualquier signo). reference_type ∈ {order, coupon,
 *    manual, system}. Idempotencia del award garantizada por UNIQUE PARCIAL
 *    sobre (reference_type='order', reference_id, type='earn').
 *
 *  - loyalty_redemptions: vínculo movement(type=redeem) ↔ coupon temporal.
 *    El cupón es single-use con expires_at corto (config). Cuando se aplica
 *    a una orden, status pasa a 'applied' y applied_order_id queda fijo.
 *
 * Identidad del cliente: (company_nit, client_phone) normalizado a
 * 57XXXXXXXXXX (sin '+') por CrmService::normalizePhone() antes de persistir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('client_phone', 30);
            $table->integer('balance')->default(0);
            $table->integer('lifetime_earned')->default(0);
            $table->string('tier', 20)->default('bronze');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->unique(['company_nit', 'client_phone'], 'loyalty_accounts_company_phone_unique');
            $table->index(['company_nit', 'tier'], 'loyalty_accounts_company_tier_idx');
            $table->index(['company_nit', 'last_activity_at'], 'loyalty_accounts_company_activity_idx');
        });

        Schema::create('loyalty_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loyalty_account_id')->constrained('loyalty_accounts')->cascadeOnDelete();
            $table->string('company_nit');
            $table->string('type', 20);
            $table->integer('points');
            $table->string('reference_type', 20)->nullable();
            $table->string('reference_id', 50)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['loyalty_account_id', 'created_at'], 'loyalty_movements_account_created_idx');
            $table->index(['company_nit', 'created_at', 'type'], 'loyalty_movements_company_created_type_idx');
            $table->index(['reference_type', 'reference_id'], 'loyalty_movements_reference_idx');
        });

        DB::statement("CREATE UNIQUE INDEX loyalty_movements_earn_per_order_unique
            ON loyalty_movements (reference_id, type)
            WHERE reference_type = 'order' AND type = 'earn'");

        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loyalty_account_id')->constrained('loyalty_accounts')->cascadeOnDelete();
            $table->foreignUuid('loyalty_movement_id')->constrained('loyalty_movements')->cascadeOnDelete();
            $table->foreignUuid('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('reward_key', 60);
            $table->unsignedInteger('points');
            $table->string('status', 20)->default('issued');
            $table->timestamp('expires_at');
            $table->timestamp('applied_at')->nullable();
            $table->foreignUuid('applied_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();

            $table->index(['loyalty_account_id', 'status'], 'loyalty_redemptions_account_status_idx');
            $table->index('expires_at', 'loyalty_redemptions_expires_idx');
            $table->index('coupon_id', 'loyalty_redemptions_coupon_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_movements');
        Schema::dropIfExists('loyalty_accounts');
    }
};
