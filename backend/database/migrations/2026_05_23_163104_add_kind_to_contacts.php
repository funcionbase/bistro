<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contact ahora declara explícitamente su tipo: persona natural
 * o jurídica (empresa). La columna era derivable de `doc_type` (NIT/NIT_EXT
 * → empresa) pero almacenarla explícita permite:
 *
 *  - Capturar la intención del cajero ANTES de elegir el tipo de documento
 *    (UX del modal: primero "Persona / Empresa", luego el catálogo doc_type
 *    se filtra).
 *  - Diferenciar un contacto sin doc (kind=null para walk-ins legacy) de
 *    uno que el cajero declaró como persona natural sin documento todavía.
 *  - Reflejar correctamente el `RecipientType` DIAN (person | company)
 *    sin recurrir a heurísticas sobre doc_type.
 *
 * Backfill: NIT/NIT_EXT → company; CC/CE/TI/PA/RC → natural; sin doc → null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('kind', 16)->nullable()->after('name');
        });

        DB::statement("ALTER TABLE contacts
            ADD CONSTRAINT contacts_kind_check
            CHECK (kind IS NULL OR kind IN ('natural', 'company'))");

        // Backfill basado en doc_type existente.
        DB::statement("UPDATE contacts SET kind = 'company' WHERE doc_type IN ('NIT', 'NIT_EXT')");
        DB::statement("UPDATE contacts SET kind = 'natural' WHERE doc_type IN ('CC', 'CE', 'TI', 'PA', 'RC')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_kind_check');

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
