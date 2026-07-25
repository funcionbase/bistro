<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking del link de carta + multi-orden por carrito (plan-mejoras-chat F3).
 *
 * - `cart_sessions.viewed_at` / `last_activity_at`: el chat muestra "abrió la
 *   carta" / "está armando el pedido" sin websockets (polling existente).
 * - `orders.cart_session_id`: una sesión de carta puede producir VARIAS
 *   órdenes (append rechazado → orden nueva). `cart_sessions.order_id` queda
 *   como la última convertida; la relación completa vive en orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_sessions', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('expired_at');
            $table->timestamp('last_activity_at')->nullable()->after('viewed_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('cart_session_id')->nullable()
                ->constrained('cart_sessions')->nullOnDelete();
            $table->index('cart_session_id');
        });

        // Backfill: las órdenes ya convertidas apuntan a su sesión.
        DB::statement(<<<'SQL'
            UPDATE orders o
            SET cart_session_id = cs.id
            FROM cart_sessions cs
            WHERE cs.order_id = o.id AND o.cart_session_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cart_session_id');
        });

        Schema::table('cart_sessions', function (Blueprint $table) {
            $table->dropColumn(['viewed_at', 'last_activity_at']);
        });
    }
};
