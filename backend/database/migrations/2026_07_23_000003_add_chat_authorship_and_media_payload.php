<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1 — columnas aditivas que consumen las fases siguientes
 * (plan 8-whatsapp.md §5.7 y §6.7).
 *
 * Van en este deploy y no en el suyo por una razón operativa: no existe entorno
 * `qa` (§4.8), así que cada migración contra pdn es riesgo puro. Tres columnas
 * aditivas en un solo paso cuestan lo mismo que ninguna; repartirlas en tres
 * deploys multiplica el riesgo sin comprar nada. La UI que las usa llega en F3
 * y el mapper que las llena, en F2.
 *
 * - `chat_messages.sent_by_user_id` (§5.7): con varios operadores en la misma
 *   bandeja, `sender='operator'` a secas no dice quién respondió qué.
 * - `chats.pending_reply_since` (§5.7): hoy la bandeja solo ordena por
 *   `last_message_at`, que mezcla "el cliente espera hace 20 min" con "ya le
 *   respondimos hace 20 min" — son estados opuestos y se ven igual.
 * - `chat_messages.media_payload` (§6.7): lo estructurado que no cabe en
 *   `media_path` — `{lat,lng,name,address}` de una ubicación,
 *   `{contacts:[{name,phones[]}]}` de un contacto, `{file_name,size_bytes,
 *   duration_s}` de documentos y audio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->uuid('sent_by_user_id')->nullable()->after('sender');
            // nullOnDelete: un mensaje enviado a un cliente es un hecho histórico
            // y no se borra con el usuario que lo escribió. Queda sin autor.
            $table->foreign('sent_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->jsonb('media_payload')->nullable()->after('media_mime');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->timestampTz('pending_reply_since')->nullable()->after('last_message_at');
        });

        // Índice parcial: solo interesan los que están esperando, que son minoría
        // frente al total histórico de chats.
        DB::statement('CREATE INDEX chats_pending_reply_idx ON chats (company_nit, pending_reply_since)
                       WHERE pending_reply_since IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS chats_pending_reply_idx');

        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn('pending_reply_since');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['sent_by_user_id']);
            $table->dropColumn(['sent_by_user_id', 'media_payload']);
        });
    }
};
