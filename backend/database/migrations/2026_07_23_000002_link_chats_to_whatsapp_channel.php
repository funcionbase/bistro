<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1 — cada chat queda atado al canal que originó la conversación
 * (plan 8-whatsapp.md §5.3).
 *
 * Esta columna es la que hace que reasignar un chat de sede NO cambie el número
 * por el que se responde (§7.2): la sede se reasigna, el canal no se toca.
 *
 * Queda `nullable` a propósito: los chats creados por el bot vía
 * `/api/external/*` en empresas sin canal conectado no tienen canal del cual
 * colgar. El índice `legacy` los cubre.
 *
 * Nombre de la constraint verificado contra la BD real:
 *   → chats_company_nit_client_phone_unique
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->uuid('whatsapp_account_id')->nullable()->after('branch_id');
            // nullOnDelete: borrar un canal no puede borrar el historial de
            // conversaciones. El chat sobrevive huérfano y cae al índice legacy.
            $table->foreign('whatsapp_account_id')->references('id')->on('company_whatsapp_accounts')->nullOnDelete();
        });

        // El backfill ata cada chat al ÚNICO canal que la empresa puede tener en
        // este momento: el de empresa (`branch_id` nulo), que es lo que existía
        // bajo el unique viejo. El filtro es explícito a propósito: sin él, el
        // día que una empresa tenga canal de empresa + canales de sede, el UPDATE
        // elegiría una fila arbitraria (PostgreSQL no garantiza cuál) y ataría
        // chats al canal equivocado.
        DB::statement('UPDATE chats SET whatsapp_account_id = a.id
                       FROM company_whatsapp_accounts a
                       WHERE a.company_nit = chats.company_nit
                         AND a.branch_id IS NULL
                         AND a.deleted_at IS NULL');

        DB::statement('ALTER TABLE chats DROP CONSTRAINT chats_company_nit_client_phone_unique');
        // El mismo cliente escribiendo al número de la empresa y al de una sede
        // son dos conversaciones distintas: son interlocutores distintos.
        DB::statement('CREATE UNIQUE INDEX chats_account_phone_unique ON chats (whatsapp_account_id, client_phone)
                       WHERE whatsapp_account_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX chats_legacy_phone_unique ON chats (company_nit, client_phone)
                       WHERE whatsapp_account_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS chats_account_phone_unique');
        DB::statement('DROP INDEX IF EXISTS chats_legacy_phone_unique');

        Schema::table('chats', function (Blueprint $table) {
            $table->dropForeign(['whatsapp_account_id']);
            $table->dropColumn('whatsapp_account_id');
            $table->unique(['company_nit', 'client_phone']);
        });
    }
};
