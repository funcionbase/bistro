<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout público enriquecido (plan-mejoras-chat F2): el cliente elige medio
 * de pago, dice con cuánto paga (efectivo) y deja notas de entrega.
 *
 * Las TRES columnas son INFORMATIVAS — no participan en `orders.total`, en la
 * base gravable ni en `payment_receipts` (constants/ACCOUNTING_RULES.md). El
 * pago real lo registra caja (closeWithPayment / courier-advance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Slug canónico de config('payments.methods') elegido por el cliente.
            $table->string('payment_preference', 20)->nullable()->after('tip_amount');
            // "¿Con cuánto vas a pagar?" (solo efectivo). Devueltas = cash_pays_with - (total + tip).
            $table->decimal('cash_pays_with', 12, 2)->nullable()->after('payment_preference');
            // Notas de la orden / indicaciones de entrega (torre, apto, portería…).
            $table->string('customer_notes', 500)->nullable()->after('cash_pays_with');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_preference', 'cash_pays_with', 'customer_notes']);
        });
    }
};
