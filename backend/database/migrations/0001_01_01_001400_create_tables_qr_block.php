<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque QR / Mesa con sesión grupal.
 *
 * Modelo nuevo para flujo de mesa con QR:
 *  - tables: mesa física por sede. qr_token único globalmente (el QR físico resuelve sede).
 *  - table_sessions: sesión grupal abierta sobre una mesa. Una sesión activa por mesa.
 *  - table_session_guests: comensal anónimo identificado por cookie device_token,
 *    enlazado al contacts del CRM (firstOrCreate por phone CO).
 *  - order_items: materialización de items de una orden con estado individual
 *    (KDS y pago dividido por comensal lo requieren).
 *  - order_notes: notas grupales o alertas para cocina (vivas además de notas
 *    individuales que viven en order_items.notes).
 *  - cancellation_requests: solicitudes de cancelación de items ya aprobados.
 *
 * Convenciones contables (CLAUDE.md):
 *  - Montos: decimal(12,2). Sin float.
 *  - payment_receipts.guest_id agrega trazabilidad de pago dividido sin mutar receipts.
 *  - order_items.unit_price es snapshot del menú al momento de agregar (nunca del payload).
 *  - cancellation_reason ∈ {customer, waiter, waiter_approved, kitchen, system, refunded}.
 *
 * Convenciones de sede:
 *  - tables.branch_id obligatorio. Unique (branch_id, number) — "Mesa 5" puede existir
 *    una vez por sede, no por empresa.
 *  - table_sessions.branch_id denormalizado para reportes por sede sin JOIN.
 *  - table_session_guests.contact_id con restrictOnDelete — auditoría contable exige
 *    no perder histórico de quién consumió.
 *
 * Nota sobre orders.items (JSON): NO se borra en esta migración. Coexiste con
 * order_items hasta que todos los readers migren.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) tables
        Schema::create('tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->string('number', 20);
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->string('qr_token', 64);
            $table->string('status', 20)->default('available');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->unique('qr_token', 'tables_qr_token_unique');
            $table->index(['company_nit', 'branch_id', 'status'], 'tables_company_branch_status_idx');
        });
        DB::statement("ALTER TABLE tables ADD CONSTRAINT tables_status_check
            CHECK (status IN ('available', 'occupied', 'reserved', 'blocked'))");
        // Unique (branch_id, number) sólo entre filas activas. Las mesas
        // archivadas conservan su número original; la renumeración tras DELETE
        // (TableAdminController) podría chocar con un archived que tenga el
        // mismo número si el unique fuera total.
        DB::statement('CREATE UNIQUE INDEX tables_branch_number_active_unique
            ON tables (branch_id, number)
            WHERE archived_at IS NULL');

        // 2) table_sessions
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('table_id')->constrained('tables')->restrictOnDelete();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->boolean('accepts_new_guests')->default(true);
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'branch_id', 'status'], 'table_sessions_company_branch_status_idx');
            $table->index(['branch_id', 'opened_at'], 'table_sessions_branch_opened_idx');
            $table->index('expires_at', 'table_sessions_expires_idx');
        });
        DB::statement("ALTER TABLE table_sessions ADD CONSTRAINT table_sessions_status_check
            CHECK (status IN ('open', 'locked', 'closed', 'expired'))");
        // Una sola sesión activa por mesa: partial unique index.
        DB::statement("CREATE UNIQUE INDEX table_sessions_one_active_per_table_idx
            ON table_sessions (table_id)
            WHERE status IN ('open', 'locked')");

        // 3) table_session_guests
        Schema::create('table_session_guests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('table_session_id')->constrained('table_sessions')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('display_name', 80);
            $table->string('phone', 15);
            $table->string('device_token', 64);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            $table->unique(['table_session_id', 'device_token'], 'tsg_session_device_unique');
            $table->index('contact_id', 'tsg_contact_idx');
            $table->index(['table_session_id', 'joined_at'], 'tsg_session_joined_idx');
        });

        // 4) order_items
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->string('menu_item_id', 64);
            $table->foreignUuid('guest_id')->nullable()->constrained('table_session_guests')->nullOnDelete();
            $table->string('name', 200);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('category', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('status', 30)->default('pending_approval');
            $table->string('cancellation_reason', 30)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('in_kitchen_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignUuid('paid_receipt_id')->nullable()->constrained('payment_receipts')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'order_items_order_status_idx');
            $table->index(['guest_id', 'status'], 'order_items_guest_status_idx');
            $table->index(['status', 'in_kitchen_at'], 'order_items_status_in_kitchen_idx');
        });
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_status_check
            CHECK (status IN ('pending_approval', 'approved', 'in_kitchen', 'ready', 'served', 'cancelled'))");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_cancellation_reason_check
            CHECK (cancellation_reason IS NULL OR cancellation_reason IN ('customer', 'waiter', 'waiter_approved', 'kitchen', 'system', 'refunded'))");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive_check
            CHECK (quantity > 0)');

        // 5) order_notes
        Schema::create('order_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 30);
            $table->string('body', 500);
            $table->nullableUuidMorphs('author');
            $table->timestamps();

            $table->index(['order_id', 'scope'], 'order_notes_order_scope_idx');
        });
        DB::statement("ALTER TABLE order_notes ADD CONSTRAINT order_notes_scope_check
            CHECK (scope IN ('group', 'kitchen_alert'))");

        // 6) cancellation_requests
        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained('table_session_guests')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('reason', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'cancellation_requests_status_created_idx');
        });
        DB::statement("ALTER TABLE cancellation_requests ADD CONSTRAINT cancellation_requests_status_check
            CHECK (status IN ('pending', 'approved', 'denied'))");

        // 7) Extender orders con table_session_id (vínculo opcional: solo órdenes de mesa con QR).
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('table_session_id')
                ->nullable()
                ->after('table_number')
                ->constrained('table_sessions')
                ->nullOnDelete();
            $table->index(['company_nit', 'table_session_id'], 'orders_company_table_session_idx');
        });

        // 8) Extender payment_receipts con guest_id (pago dividido por comensal).
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->foreignUuid('guest_id')
                ->nullable()
                ->after('order_id')
                ->constrained('table_session_guests')
                ->nullOnDelete();
            $table->index(['order_id', 'guest_id'], 'payment_receipts_order_guest_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropForeign(['guest_id']);
            $table->dropIndex('payment_receipts_order_guest_idx');
            $table->dropColumn('guest_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['table_session_id']);
            $table->dropIndex('orders_company_table_session_idx');
            $table->dropColumn('table_session_id');
        });

        Schema::dropIfExists('cancellation_requests');
        Schema::dropIfExists('order_notes');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('table_session_guests');
        Schema::dropIfExists('table_sessions');
        Schema::dropIfExists('tables');
    }
};
