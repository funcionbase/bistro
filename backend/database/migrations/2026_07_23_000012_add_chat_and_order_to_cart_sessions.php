<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga la sesión de carrito al chat que la originó y a la orden en que
 * convirtió. Unifica "enviar la carta" y "enviar carrito" desde /chats: el
 * operador manda un link corto (/menus?cart={uuid}) y, cuando el cliente
 * confirma el pedido desde la carta, el sistema precarga en la conversación
 * lo que seleccionó (ChatMessage con el resumen) vía `chat_id`.
 *
 * `jwt_jti` se reusa como token público del link (ya es UNIQUE); para estas
 * sesiones guarda un UUID propio, no el jti de un CartJWT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_sessions', function (Blueprint $table) {
            $table->foreignUuid('chat_id')->nullable()->after('branch_id')
                ->constrained('chats')->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->after('chat_id')
                ->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cart_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chat_id');
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
