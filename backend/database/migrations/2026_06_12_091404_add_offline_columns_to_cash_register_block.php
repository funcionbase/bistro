<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas para apertura/egreso/cierre de caja offline (plan-off.md §6.2).
 *
 * - cash_register_sessions.client_uuid + UNIQUE parcial → apertura/cierre
 *   idempotentes offline (reintento del sync no abre/cierra dos veces).
 * - cash_register_expenses.client_uuid + UNIQUE parcial → egreso idempotente.
 * - *_at_client: hora real del evento en el dispositivo (cuadre por esa hora,
 *   no por el `created_at`/`opened_at` del server que puede ser horas después).
 *
 * Aditiva y nullable: no rompe filas existentes ni el flujo online.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->after('id');
            $table->timestamp('opened_at_client')->nullable()->after('opened_at');
            $table->timestamp('closed_at_client')->nullable()->after('closed_at');
        });
        // Idempotencia de apertura offline: un client_uuid abre UNA sesión por sede.
        DB::statement('CREATE UNIQUE INDEX idx_cash_session_client_uuid
            ON cash_register_sessions (company_nit, branch_id, client_uuid)
            WHERE client_uuid IS NOT NULL');

        Schema::table('cash_register_expenses', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->after('id');
            $table->timestamp('occurred_at_client')->nullable()->after('created_at');
        });
        DB::statement('CREATE UNIQUE INDEX idx_cash_expense_client_uuid
            ON cash_register_expenses (client_uuid)
            WHERE client_uuid IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_cash_expense_client_uuid');
        DB::statement('DROP INDEX IF EXISTS idx_cash_session_client_uuid');

        Schema::table('cash_register_expenses', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'occurred_at_client']);
        });
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'opened_at_client', 'closed_at_client']);
        });
    }
};
