<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cliente DIAN por defecto por empresa.
 *
 * Cuando una empresa quiere que sus emisiones automáticas (DEE POS sin
 * adquirente capturado) usen un adquirente fijo distinto al "CONSUMIDOR
 * FINAL DIAN" estándar (NIT 222222222222), configura aquí su override.
 *
 * Cascada de resolución del adquirente (§5.3 del refinamiento):
 *   1. Contact lookup por phone (perfil DIAN completo).
 *   2. Datos capturados en modal por el cajero.
 *   3. dian_default_recipients[company_nit]   ← esta tabla.
 *   4. config('dian.default_final_consumer')  ← CONSUMIDOR FINAL estándar.
 *
 * UNIQUE en `company_nit` → un solo default por empresa. Cada empresa puede
 * sobreescribirlo cuando quiera (PUT al endpoint owner-only). Si la fila no
 * existe, se cae a la convención DIAN sin error.
 *
 * `applies_to_auto_emit_only`:
 *  - `true` (default): solo se usa cuando el cajero NO captura adquirente
 *    explícito (camino feliz "Pagar y emitir" sin abrir modal).
 *  - `false`: también se usa cuando el cajero pulsa "Emitir documento" sin
 *    haber capturado adquirente. Útil para tiendas que casi siempre facturan
 *    a la misma empresa madre (cadenas de franquicia, ventas internas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_default_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_nit');
            $table->string('doc_type', 8);
            $table->string('doc_number', 30);
            $table->string('dv', 1)->nullable();
            $table->string('legal_name');
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('municipality_dane_code', 5)->nullable();
            $table->jsonb('fiscal_responsibilities')->nullable();
            $table->boolean('applies_to_auto_emit_only')->default(true);
            $table->timestamps();

            $table->foreign('company_nit')->references('nit')->on('companies')->cascadeOnDelete();
            $table->unique('company_nit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_default_recipients');
    }
};
