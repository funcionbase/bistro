<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guard anti-duplicado del recibo térmico por WhatsApp (plan-mejoras-chat F4).
 *
 * "Vigente" ⇔ `receipt_sent_total == total + tip_amount`: si el pedido cambió
 * después de enviado (append del cliente, cambio de tipo), el recibo queda
 * stale y el panel del chat ofrece "Reenviar"; si no cambió, el botón queda
 * deshabilitado (409 RECEIPT_ALREADY_SENT) para evitar spam por doble click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('receipt_sent_at')->nullable()->after('customer_notes');
            $table->decimal('receipt_sent_total', 12, 2)->nullable()->after('receipt_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['receipt_sent_at', 'receipt_sent_total']);
        });
    }
};
