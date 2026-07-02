<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot tributario por línea para las filas `order_items` (#293).
 *
 * Las líneas de caja (`orders.items` JSON) ya congelan `tax_rate` efectiva al
 * crearse; las filas `order_items` del flujo QR no, así que
 * `OrderTotalCalculator` no podía reconstruir el desglose IVA/INC de la orden.
 * Nullable: las filas legacy caen a `orders.snapshot_default_tax_rate` al
 * calcular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
