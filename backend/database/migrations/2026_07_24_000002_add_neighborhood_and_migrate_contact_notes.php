<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Agrega `contacts.neighborhood` (barrio): la dirección del contacto pasa a
 *    ser estructurada (departamento+ciudad vía municipality_dane_code, barrio,
 *    dirección). `address` y `municipality_dane_code` ya existían (perfil DIAN).
 *
 * 2. Unifica las notas: hasta ahora `/chats` editaba `contacts.notes` (texto
 *    único) mientras `/clients` "Notas privadas" usa `client_notes` (lista con
 *    autor/fecha). Se migra cada `contacts.notes` no vacío a una fila de
 *    `client_notes` para que ambas vistas muestren lo mismo. Idempotente
 *    (NOT EXISTS) y sin autor (created_by NULL = nota histórica del sistema).
 *    La columna `contacts.notes` se conserva por compatibilidad pero el código
 *    deja de leerla/escribirla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('neighborhood', 120)->nullable()->after('address');
        });

        // Migra las notas viejas del contacto a client_notes (Notas privadas).
        DB::statement(<<<'SQL'
            INSERT INTO client_notes (id, company_nit, contact_id, client_phone, note, created_by, created_at, updated_at)
            SELECT gen_random_uuid(), c.company_nit, c.id, c.phone, c.notes, NULL, now(), now()
            FROM contacts c
            WHERE c.notes IS NOT NULL AND btrim(c.notes) <> ''
              AND NOT EXISTS (
                SELECT 1 FROM client_notes n
                WHERE n.contact_id = c.id AND n.note = c.notes AND n.deleted_at IS NULL
              )
        SQL);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('neighborhood');
        });
        // Las notas migradas NO se revierten: quedan como notas privadas legítimas.
    }
};
