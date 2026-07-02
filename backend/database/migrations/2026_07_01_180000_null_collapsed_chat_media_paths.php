<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CIBER-04: los `media_path` viejos se construían con `sprintf('%d', <uuid>)`,
 * colapsando a `chat-media/<dígito>/<dígito>.ext`. Esos paths colisionaban entre
 * mensajes/empresas (un `Storage::put` sobreescribía al anterior), de modo que la
 * URL servía media de OTRO cliente. Se anulan para dejar de servir media ajena;
 * los mensajes con `media_meta_id` vigente pueden re-descargarse (DownloadWhatsappMediaJob).
 *
 * Discriminador seguro: los paths nuevos usan UUID (con guiones y letras), así que
 * `^chat-media/[0-9]+/[0-9]+\.` solo matchea el formato colapsado viejo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Solo pgsql soporta el operador regex `~`. En otros drivers (sqlite de
        // dev/test) se omite: no hay datos colapsados que limpiar.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('chat_messages')
            ->whereNotNull('media_path')
            ->whereRaw("media_path ~ '^chat-media/[0-9]+/[0-9]+\\.'")
            ->update(['media_path' => null]);
    }

    public function down(): void
    {
        // Irreversible: los paths colapsados apuntaban a archivos colisionados
        // (datos ya corruptos). No se restauran.
    }
};
