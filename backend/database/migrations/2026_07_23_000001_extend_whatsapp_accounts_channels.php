<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1 — `company_whatsapp_accounts` pasa de "una cuenta Meta por empresa" a
 * "canal de WhatsApp" (plan 8-whatsapp.md §5.2).
 *
 * `branch_id` NULL = canal de empresa; `branch_id` con UUID = canal de una sede.
 * Los tres índices únicos parciales fijan la invariante: máximo un canal de
 * empresa y máximo un canal por sede. No se agrega columna `provider`: hay un
 * solo proveedor y hoy sería una columna con valor constante.
 *
 * Compatible hacia atrás (instance refresh corre código viejo contra el schema
 * nuevo): todo es aditivo salvo el reemplazo del unique de `company_nit`, que
 * pasa a ser el mismo unique restringido a las filas que el código viejo genera
 * (`branch_id` nulo porque ni conoce la columna). El código viejo sigue haciendo
 * `updateOrCreate` por `company_nit` sin notar el cambio.
 *
 * El nombre de la constraint que se dropea está verificado contra la BD real,
 * no deducido de la convención de Laravel:
 *   SELECT conname FROM pg_constraint WHERE conrelid = 'company_whatsapp_accounts'::regclass
 *   → company_whatsapp_accounts_company_nit_unique
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_whatsapp_accounts', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable()->after('company_nit');   // NULL = canal de empresa
            $table->string('label', 60)->nullable();                       // "Sede Norte", "Pedidos"
            $table->string('evo_server_url')->nullable();                  // multi-servidor futuro
            $table->string('evo_instance', 80)->nullable();                // nombre de instancia
            $table->text('evo_token_encrypted')->nullable();               // token por instancia
            $table->text('inbound_secret_encrypted')->nullable();          // header del webhook entrante
            $table->timestamp('last_connection_check_at')->nullable();

            // restrictOnDelete: archivar una sede con WhatsApp conectado tiene que
            // fallar ruidoso, no llevarse el canal en silencio (§7.4).
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['company_nit', 'branch_id'], 'cwa_company_branch_idx');
        });

        DB::statement('ALTER TABLE company_whatsapp_accounts DROP CONSTRAINT company_whatsapp_accounts_company_nit_unique');

        // `deleted_at IS NULL` en los dos primeros: desconectar hace soft-delete y
        // tiene que liberar el slot para poder reconectar el mismo número.
        DB::statement('CREATE UNIQUE INDEX cwa_company_scope_unique ON company_whatsapp_accounts (company_nit)
                       WHERE branch_id IS NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX cwa_branch_scope_unique ON company_whatsapp_accounts (branch_id)
                       WHERE branch_id IS NOT NULL AND deleted_at IS NULL');
        // Sin filtro de soft-delete: el nombre de instancia vive en Evolution, que
        // no sabe de nuestros borrados lógicos. Reusarlo pisaría una sesión ajena.
        DB::statement('CREATE UNIQUE INDEX cwa_evo_instance_unique ON company_whatsapp_accounts (evo_instance)
                       WHERE evo_instance IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cwa_company_scope_unique');
        DB::statement('DROP INDEX IF EXISTS cwa_branch_scope_unique');
        DB::statement('DROP INDEX IF EXISTS cwa_evo_instance_unique');

        Schema::table('company_whatsapp_accounts', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex('cwa_company_branch_idx');
            $table->dropColumn([
                'branch_id', 'label', 'evo_server_url', 'evo_instance',
                'evo_token_encrypted', 'inbound_secret_encrypted', 'last_connection_check_at',
            ]);
            $table->unique('company_nit');
        });
    }
};
