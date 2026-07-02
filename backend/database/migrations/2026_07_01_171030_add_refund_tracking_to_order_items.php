<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de devoluciones por item (caja de mesas QR).
 *
 * Antes, TableCashierService::refundItem marcaba el item `cancelled`
 * (reason=refunded) y recalculaba `orders.total` — mutación retroactiva de una
 * orden completed que además producía doble descuento en reportes (el total
 * bajaba Y el receipt negativo restaba de nuevo). Ahora la venta queda intacta
 * y la devolución vive SOLO en el receipt negativo (CLAUDE.md §13); estas
 * columnas marcan el item devuelto para la UI y bloquean el doble refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('paid_receipt_id');
            $table->uuid('refund_receipt_id')->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['refunded_at', 'refund_receipt_id']);
        });
    }
};
