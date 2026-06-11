<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 07 — Orders + Cart + Receipts.
 *
 * Núcleo transaccional del sistema. Reglas contables clave:
 *  - Todos los montos: decimal(12,2). Tasas: decimal(5,2). Sin float.
 *  - orders.total = SUM(items.price * items.quantity); ya viene NETO de descuento.
 *  - tip_amount NO suma a total/subtotal y NO genera impuesto.
 *  - payment_receipts.amount es SIGNED (cobro positivo, refund negativo).
 *  - payment_method ∈ {cash, card, transfer, refund} (lista cerrada).
 *  - payment_receipts INMUTABLES: nunca UPDATE de monto/método; sólo se agregan filas.
 *  - branch_id NOT NULL desde inicio (BranchScope global).
 *
 * Estados de orden canónicos (config/orders.php):
 *  pending, in_kitchen, ready, in_transit, completed, cancelled, refunded, abandoned
 *
 * Índices estructurales:
 *  - (company_nit, ordered_at DESC, status) — summary KPI
 *  - parcial (company_nit, status) WHERE status IN active — polling kanban
 *  - parcial (company_nit, ordered_at) WHERE status='completed' — heatmap horario
 *  - functional (company_nit, ordered_at::date, status) — agregaciones por día
 *  - parcial unique (company_nit, client_uuid) WHERE client_uuid IS NOT NULL — idempotencia offline
 *  - parcial unique (client_uuid) WHERE client_uuid IS NOT NULL en payment_receipts
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_uuid')->nullable();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('session_id')->nullable()->index();
            $table->string('client_phone', 30)->nullable();
            $table->json('items')->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('order_type', 20)->nullable();
            $table->string('table_number', 20)->nullable();
            $table->text('delivery_address')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('snapshot_default_tax_rate', 5, 2)->default(0);
            $table->string('tax_regime', 20)->nullable();
            $table->boolean('tax_included_in_price')->default(true);
            $table->decimal('tip_amount', 12, 2)->default(0);
            $table->string('coupon_code', 32)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->nullable()->default(0);
            $table->timestamp('inventory_consumed_at')->nullable();
            $table->jsonb('sync_warnings')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'ordered_at']);
            $table->index(['company_nit', 'status']);
            $table->index(['company_nit', 'branch_id'], 'orders_company_branch_idx');
            $table->index(['branch_id', 'created_at'], 'orders_branch_created_idx');
        });
        DB::statement('CREATE UNIQUE INDEX orders_company_nit_client_uuid_unique
            ON orders (company_nit, client_uuid)
            WHERE client_uuid IS NOT NULL');
        DB::statement('CREATE INDEX idx_orders_company_ordered_status
            ON orders (company_nit, ordered_at DESC, status)');
        DB::statement("CREATE INDEX idx_orders_company_status_active
            ON orders (company_nit, status)
            WHERE status IN ('pending', 'in_kitchen', 'ready', 'in_transit')");
        DB::statement("CREATE INDEX idx_orders_hourly_heatmap
            ON orders (company_nit, ordered_at)
            WHERE status IN ('completed')");
        DB::statement('CREATE INDEX idx_orders_company_date_status
            ON orders (company_nit, (ordered_at::date), status)');
        // Índices GIN sobre JSON/JSONB para queries con operadores @>, ?, ?|, ?&.
        // items se castea a jsonb porque la columna es json (text). sync_warnings
        // ya es jsonb. Filtra órdenes con warnings (NULL es la mayoría de filas).
        DB::statement('CREATE INDEX idx_orders_items_gin
            ON orders USING GIN ((items::jsonb) jsonb_path_ops)');
        DB::statement('CREATE INDEX idx_orders_sync_warnings_gin
            ON orders USING GIN (sync_warnings jsonb_path_ops)
            WHERE sync_warnings IS NOT NULL');

        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_uuid')->nullable();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('file_path')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('reference', 120)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->uuid('cash_session_id')->nullable();
            $table->json('payment_data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index('order_id');
            $table->index('company_nit');
            $table->index(['company_nit', 'payment_method'], 'idx_pmt_receipts_company_method');
            $table->index(['company_nit', 'paid_at'], 'idx_pmt_receipts_company_paid_at');
            $table->index(['company_nit', 'branch_id'], 'payment_receipts_company_branch_idx');
            $table->index(['branch_id', 'paid_at'], 'payment_receipts_branch_paid_idx');
            $table->index('cash_session_id', 'idx_pmt_receipts_cash_session');
        });
        DB::statement('CREATE UNIQUE INDEX payment_receipts_client_uuid_unique
            ON payment_receipts (client_uuid)
            WHERE client_uuid IS NOT NULL');

        Schema::create('cart_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('jwt_jti')->unique();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('client_phone', 30);
            $table->string('status', 20)->default('active');
            $table->timestamp('expired_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'status']);
            $table->index('expired_at');
            $table->index(['company_nit', 'branch_id'], 'cart_sessions_company_branch_idx');
        });
        DB::statement('CREATE INDEX idx_cart_sessions_company_today
            ON cart_sessions (company_nit, status, created_at DESC)');

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_session_id')->constrained('cart_sessions')->cascadeOnDelete();
            $table->uuid('branch_id');
            $table->string('menu_item_id', 64);
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('category', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index('cart_session_id');
            $table->index(['branch_id', 'created_at'], 'cart_items_branch_created_idx');
        });

        Schema::create('offline_sync_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->uuid('user_id')->nullable();
            $table->string('event_type', 32);
            $table->unsignedInteger('count')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_nit', 'occurred_at']);
            $table->index(['company_nit', 'event_type', 'occurred_at']);
            $table->index(['company_nit', 'branch_id'], 'offline_sync_events_company_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_events');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('cart_sessions');
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('orders');
    }
};
