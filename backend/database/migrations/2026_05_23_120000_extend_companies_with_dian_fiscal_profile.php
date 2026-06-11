<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil fiscal DIAN del emisor.
 *
 * Extiende `companies` con todo lo que el XML UBL 2.1 Colombia exige en el
 * bloque `<cac:AccountingSupplierParty>`:
 *
 *  - `dv`: dígito verificación (1 char) del NIT. Se imprime junto al NIT en la
 *    representación gráfica DIAN. NO se incluye en el NIT canónico ni en el
 *    CUFE (DIAN exige NIT sin DV en la concatenación). Nullable porque no
 *    todas las empresas existentes lo tienen capturado todavía.
 *  - `legal_representative_*`: representante legal (datos para correspondencia
 *    DIAN ante incidencias y para el bloque de identificación del emisor).
 *  - `economic_activity_code`: CIIU 4 dígitos. Obligatorio para el XML.
 *  - `fiscal_responsibilities`: jsonb con slugs DIAN (`R-99-PN`, `O-13`, etc.).
 *    Lista vacía por default — el owner los marca en `Configuración → DIAN`.
 *  - `tax_obligations`: jsonb con slugs adicionales (cuando aplique).
 *  - `municipality_dane_code`: 5 chars (CCDDMMM). Fallback para el municipio
 *    del consumidor final genérico.
 *  - `billing_email`, `billing_phone`, `physical_address`: contacto fiscal del
 *    emisor (distinto del de la cuenta SaaS).
 *  - `country_code`: ISO 3166-1 alpha-2, default 'CO'. Reservado para
 *    multipaís futuro; el XML UBL ya lo exige.
 *
 * Todas las columnas son nullable (excepto `country_code` con default) para
 * no romper las empresas existentes. La UI obliga el llenado antes de emitir
 * el primer documento DIAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('dv', 1)->nullable()->after('nit');
            $table->string('legal_representative_name')->nullable()->after('legal_name');
            $table->string('legal_representative_doc_type', 8)->nullable()->after('legal_representative_name');
            $table->string('legal_representative_doc_number', 30)->nullable()->after('legal_representative_doc_type');
            $table->string('economic_activity_code', 4)->nullable()->after('legal_representative_doc_number');
            $table->jsonb('fiscal_responsibilities')->nullable()->after('economic_activity_code');
            $table->jsonb('tax_obligations')->nullable()->after('fiscal_responsibilities');
            $table->string('municipality_dane_code', 5)->nullable()->after('tax_obligations');
            $table->string('billing_email')->nullable()->after('municipality_dane_code');
            $table->string('billing_phone', 30)->nullable()->after('billing_email');
            $table->string('physical_address')->nullable()->after('billing_phone');
            $table->string('country_code', 2)->default('CO')->after('physical_address');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'dv',
                'legal_representative_name',
                'legal_representative_doc_type',
                'legal_representative_doc_number',
                'economic_activity_code',
                'fiscal_responsibilities',
                'tax_obligations',
                'municipality_dane_code',
                'billing_email',
                'billing_phone',
                'physical_address',
                'country_code',
            ]);
        });
    }
};
