<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #275 Fase 4 — Feedback al usuario que disparó el cambio de estado cuando el
 * SMS al cliente falla (envío async en SendOrderStatusSmsJob).
 *
 * - `user_id`: usuario (JWT sub) que ejecutó el cambio de estado y debe
 *   recibir el aviso. Nullable: cambios sin usuario (flujos automáticos/sistema)
 *   simplemente no notifican a nadie.
 * - `user_notified_at`: marca de "ya se le mostró el aviso al usuario" (ack del
 *   frontend). NULL = pendiente de mostrar. Garantiza el "una sola vez" a nivel
 *   servidor (idempotente entre dispositivos y N instancias).
 *
 * PDN-safe: solo agrega columnas nullable + un índice; no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_sms_notifications', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable()->after('phone')
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('user_notified_at')->nullable()->after('error');

            // Query del feedback: fallidos de un usuario aún no vistos.
            $table->index(['user_id', 'status', 'user_notified_at'], 'order_sms_notifications_user_feedback_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_sms_notifications', function (Blueprint $table) {
            $table->dropIndex('order_sms_notifications_user_feedback_idx');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('user_notified_at');
        });
    }
};
