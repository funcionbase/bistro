<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 08 — Cash register + printers.
 *
 * Sesiones de caja (turnos), egresos de caja, e impresoras del local.
 *
 * cash_register_sessions:
 *  - 1 sesión `open` por (company_nit, branch_id) (UNIQUE INDEX parcial).
 *  - Sesión `closed` es inmutable: corrección = nueva sesión con notas.
 *  - expected_cash al cerrar = opening + ingresos cash + propinas cash − refunds cash − egresos cash.
 *  - cash_difference = closing_amount − expected_cash. Informativo para auditoría.
 *
 * cash_register_expenses:
 *  - Append-only (sólo created_at, no updated_at). Para corregir, registrar uno nuevo.
 *  - amount > 0 enforced por CHECK constraint.
 *
 * Al final, agrega la FK de payment_receipts.cash_session_id (cuya columna ya existe
 * desde el bloque 07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->foreignUuid('opened_by_user_id')->constrained('users');
            $table->timestamp('opened_at');
            $table->decimal('opening_amount', 12, 2)->default(0);
            $table->foreignUuid('closed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('closing_amount', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('cash_difference', 12, 2)->nullable();
            $table->string('status', 16)->default('open');
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'status']);
            $table->index(['company_nit', 'opened_at']);
            $table->index(['company_nit', 'branch_id'], 'cash_register_sessions_company_branch_idx');
        });
        DB::statement("CREATE UNIQUE INDEX idx_cash_session_one_open_per_branch
            ON cash_register_sessions (company_nit, branch_id)
            WHERE status = 'open'");

        Schema::create('cash_register_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cash_session_id')->constrained('cash_register_sessions')->cascadeOnDelete();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->decimal('amount', 12, 2);
            $table->string('category', 32);
            $table->string('payment_method', 16)->default('cash');
            $table->string('description', 500)->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index('cash_session_id');
            $table->index(['company_nit', 'created_at']);
            $table->index(['company_nit', 'branch_id'], 'cash_register_expenses_company_branch_idx');
        });
        DB::statement('ALTER TABLE cash_register_expenses ADD CONSTRAINT cash_register_expenses_amount_positive CHECK (amount > 0)');

        Schema::create('printers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_nit', 20);
            $table->uuid('branch_id');
            $table->string('name', 120);
            $table->string('type', 32);
            $table->string('connection', 32);
            $table->string('address', 255);
            $table->unsignedSmallInteger('paper_width')->default(80);
            $table->json('categories')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'type']);
            $table->index(['company_nit', 'branch_id'], 'printers_company_branch_idx');
        });

        // FK pendiente del bloque 07 (la columna ya existe en payment_receipts).
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->foreign('cash_session_id')
                ->references('id')->on('cash_register_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
        });

        Schema::dropIfExists('printers');
        Schema::dropIfExists('cash_register_expenses');
        Schema::dropIfExists('cash_register_sessions');
    }
};
