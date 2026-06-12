<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas para la caja offline-first (plan-off.md §6.2).
 *
 * - orders.created_at_client / payment_receipts.occurred_at_client: hora REAL
 *   de la venta/cobro en el dispositivo. El cuadre de caja se agrupa por esta
 *   hora (no por `paid_at` del server, que puede ser horas después al sync).
 * - orders.is_offline_origin: métrica/auditoría de órdenes nacidas offline.
 *
 * Aditiva y nullable: no rompe filas existentes ni el flujo online.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('created_at_client')->nullable()->after('ordered_at');
            $table->boolean('is_offline_origin')->default(false)->after('created_at_client');
        });

        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->timestamp('occurred_at_client')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['created_at_client', 'is_offline_origin']);
        });

        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropColumn('occurred_at_client');
        });
    }
};
