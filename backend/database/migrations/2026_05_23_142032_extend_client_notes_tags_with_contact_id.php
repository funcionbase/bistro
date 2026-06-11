<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `client_notes` y `client_tags` migran de `client_phone` a
 * `contact_id` como llave canónica del cliente.
 *
 * Backfill: por cada fila existente, si existe Contact único para
 * `(company_nit, client_phone)`, set contact_id. Si hay 0 o múltiples,
 * dejar NULL — las filas legacy siguen accesibles por phone pero quedan
 * marcadas para reconciliación manual.
 *
 * `client_phone` permanece como denormalizado por backwards-compat con
 * queries existentes; eventualmente se deprecará en una migración futura
 * una vez el frontend complete la transición.
 *
 * UNIQUE de tags se rehace sobre `(company_nit, contact_id, tag)` en
 * paralelo con el legacy. Filas con contact_id NULL siguen bajo el
 * UNIQUE viejo por phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_notes', function (Blueprint $table): void {
            $table->foreignUuid('contact_id')
                ->nullable()
                ->after('client_phone')
                ->constrained('contacts')
                ->nullOnDelete();
            $table->index(['company_nit', 'contact_id', 'created_at'], 'client_notes_company_contact_created_idx');
        });

        Schema::table('client_tags', function (Blueprint $table): void {
            $table->foreignUuid('contact_id')
                ->nullable()
                ->after('client_phone')
                ->constrained('contacts')
                ->nullOnDelete();
        });

        // Backfill: contact_id por lookup único de phone.
        DB::statement(<<<'SQL'
            UPDATE client_notes n
            SET contact_id = sub.id
            FROM (
                SELECT c.id, c.company_nit, c.phone
                FROM contacts c
                WHERE c.phone IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM contacts c2
                      WHERE c2.company_nit = c.company_nit
                        AND c2.phone = c.phone
                        AND c2.id <> c.id
                  )
            ) sub
            WHERE n.contact_id IS NULL
              AND n.company_nit = sub.company_nit
              AND n.client_phone = sub.phone
        SQL);

        DB::statement(<<<'SQL'
            UPDATE client_tags t
            SET contact_id = sub.id
            FROM (
                SELECT c.id, c.company_nit, c.phone
                FROM contacts c
                WHERE c.phone IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM contacts c2
                      WHERE c2.company_nit = c.company_nit
                        AND c2.phone = c.phone
                        AND c2.id <> c.id
                  )
            ) sub
            WHERE t.contact_id IS NULL
              AND t.company_nit = sub.company_nit
              AND t.client_phone = sub.phone
        SQL);

        // UNIQUE parcial nuevo: (company_nit, contact_id, tag) cuando contact_id
        // está presente. El UNIQUE viejo por phone queda como fallback para
        // filas legacy sin contact resuelto.
        DB::statement('CREATE UNIQUE INDEX client_tags_company_contact_tag_unique
            ON client_tags (company_nit, contact_id, tag)
            WHERE contact_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS client_tags_company_contact_tag_unique');

        Schema::table('client_tags', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });

        Schema::table('client_notes', function (Blueprint $table): void {
            $table->dropIndex('client_notes_company_contact_created_idx');
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });
    }
};
