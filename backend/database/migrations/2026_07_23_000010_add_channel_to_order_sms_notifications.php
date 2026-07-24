<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp primario / SMS de respaldo: la notificación de cambio de estado de
 * orden se envía por WhatsApp cuando el cliente escribió por ese canal en las
 * últimas horas (ventana iniciada por él) y hay canal Evolution conectado; si
 * no, cae al SMS. `channel` deja registrado por dónde salió CADA notificación.
 *
 * Es necesario para que el reporte de "SMS enviados" (MetricsService, con costo
 * real por segmento) NO cuente las notificaciones que salieron gratis por
 * WhatsApp: sin esta columna, `status='sent'` mezcla ambos canales e infla el
 * costo reportado.
 *
 * PDN-safe: columna aditiva con default 'sms' (todas las filas históricas eran
 * SMS). No toca ni borra datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_sms_notifications', function (Blueprint $table) {
            // Canal efectivo de la notificación: 'sms' (Amazon SNS) | 'whatsapp'
            // (Evolution). Default 'sms' → histórico y respaldo. El reporte de
            // costo filtra channel='sms'.
            $table->string('channel', 12)->default('sms')->after('to_status');
        });
    }

    public function down(): void
    {
        Schema::table('order_sms_notifications', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
