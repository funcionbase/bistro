<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entradas de efectivo a caja (ingresos NO derivados de una venta): aportes de
 * socio, préstamos/inyecciones de capital, ajustes positivos, otros.
 *
 * Espejo estructural de `cash_register_expenses`:
 *  - Append-only (solo `created_at`, sin `updated_at`). Para corregir, registrar
 *    otro movimiento — nunca UPDATE/DELETE (trazabilidad DIAN).
 *  - `amount > 0` forzado por CHECK constraint.
 *  - `client_uuid` + UNIQUE parcial → idempotencia del sync offline.
 *
 * INCREMENTA `expected_cash` cuando `payment_method = cash`. Se decidió tabla
 * separada (no una columna `direction` sobre expenses) para que las queries de
 * egreso existentes sigan restando SOLO egresos: un ingreso mal clasificado en
 * la tabla de egresos se restaría del efectivo (corrupción silenciosa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_incomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_uuid')->nullable();
            $table->foreignUuid('cash_session_id')->constrained('cash_register_sessions')->cascadeOnDelete();
            $table->string('company_nit');
            $table->uuid('branch_id');
            $table->decimal('amount', 12, 2);
            $table->string('category', 32);
            $table->string('payment_method', 16)->default('cash');
            $table->string('description', 500)->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('occurred_at_client')->nullable();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index('cash_session_id');
            $table->index(['company_nit', 'created_at']);
            $table->index(['company_nit', 'branch_id'], 'cash_register_incomes_company_branch_idx');
        });

        // amount siempre positivo — el signo del movimiento lo da la tabla, no el valor.
        DB::statement('ALTER TABLE cash_register_incomes ADD CONSTRAINT cash_register_incomes_amount_positive CHECK (amount > 0)');

        // Idempotencia del sync offline: un client_uuid registra UN ingreso.
        DB::statement('CREATE UNIQUE INDEX idx_cash_income_client_uuid
            ON cash_register_incomes (client_uuid)
            WHERE client_uuid IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_cash_income_client_uuid');
        Schema::dropIfExists('cash_register_incomes');
    }
};
