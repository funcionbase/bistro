<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil fiscal DIAN del adquirente capturado en `contacts`.
 *
 * `contacts` ya es único por (company_nit, phone). Lo que agregamos son los
 * mínimos del adquirente para construir el XML UBL 2.1 cuando el cajero
 * encuentra al cliente por teléfono y necesita emitir Factura Electrónica
 * (FEV) en vez de DEE POS.
 *
 *  - `doc_type` / `doc_number` / `dv`: identificación. Reglas:
 *      * `CC|NIT|CE|TI|PA|RC|NIT_EXT` (catálogo DIAN).
 *      * `dv` solo aplica a `NIT` (1 char).
 *  - `legal_name`: razón social cuando el adquirente es empresa, o nombre
 *    completo cuando es persona natural.
 *  - `email`: para envío del XML/PDF.
 *  - `address`: dirección fiscal.
 *  - `municipality_dane_code`: 5 chars.
 *  - `fiscal_responsibilities`: jsonb (R-99-PN por default cuando es persona).
 *  - `dian_profile_completed_at`: marca cuándo se completaron los mínimos.
 *    Mientras esté NULL → el lookup retorna `needs_recipient_data` y la UI
 *    pide los datos faltantes en un modal.
 *
 * El par (company_nit, phone) sigue siendo único — un mismo cliente puede
 * existir para otra empresa con su propio perfil DIAN; multi-tenant intacto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('doc_type', 8)->nullable()->after('name');
            $table->string('doc_number', 30)->nullable()->after('doc_type');
            $table->string('dv', 1)->nullable()->after('doc_number');
            $table->string('legal_name')->nullable()->after('dv');
            $table->string('email')->nullable()->after('legal_name');
            $table->string('address')->nullable()->after('email');
            $table->string('municipality_dane_code', 5)->nullable()->after('address');
            $table->jsonb('fiscal_responsibilities')->nullable()->after('municipality_dane_code');
            $table->timestamp('dian_profile_completed_at')->nullable()->after('fiscal_responsibilities');

            $table->index(['company_nit', 'doc_number'], 'contacts_company_doc_number_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_company_doc_number_idx');
            $table->dropColumn([
                'doc_type',
                'doc_number',
                'dv',
                'legal_name',
                'email',
                'address',
                'municipality_dane_code',
                'fiscal_responsibilities',
                'dian_profile_completed_at',
            ]);
        });
    }
};
