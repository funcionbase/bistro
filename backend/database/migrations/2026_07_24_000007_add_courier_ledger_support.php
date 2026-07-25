<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger de efectivo del domiciliario (plan-mejoras-chat F6).
 *
 * - `cash_register_expenses.courier_user_id`: vincula el egreso
 *   `domiciliario_pago` (pago de tarifas al cierre) con el repartidor.
 * - Razón `no_show` en las listas cerradas de deliveries: entrega fallida /
 *   nadie recibe. El abono del domiciliario se modela como PaymentReceipt
 *   normal (cash + payment_data.courier_advance) — sin columnas nuevas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_expenses', function (Blueprint $table) {
            $table->foreignUuid('courier_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE delivery_status_logs DROP CONSTRAINT IF EXISTS delivery_status_logs_reason_check');
        DB::statement(<<<'SQL'
            ALTER TABLE delivery_status_logs
            ADD CONSTRAINT delivery_status_logs_reason_check
            CHECK (reason IS NULL OR reason IN ('error_usuario', 'pedido_rechazado', 'reassigned', 'no_show'))
        SQL);

        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT IF EXISTS deliveries_status_change_reason_check');
        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_status_change_reason_check
            CHECK (status_change_reason IS NULL OR status_change_reason IN ('error_usuario', 'pedido_rechazado', 'no_show'))
        SQL);
    }

    public function down(): void
    {
        Schema::table('cash_register_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_user_id');
        });

        DB::statement('ALTER TABLE delivery_status_logs DROP CONSTRAINT IF EXISTS delivery_status_logs_reason_check');
        DB::statement(<<<'SQL'
            ALTER TABLE delivery_status_logs
            ADD CONSTRAINT delivery_status_logs_reason_check
            CHECK (reason IS NULL OR reason IN ('error_usuario', 'pedido_rechazado', 'reassigned'))
        SQL);

        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT IF EXISTS deliveries_status_change_reason_check');
        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_status_change_reason_check
            CHECK (status_change_reason IS NULL OR status_change_reason IN ('error_usuario', 'pedido_rechazado'))
        SQL);
    }
};
