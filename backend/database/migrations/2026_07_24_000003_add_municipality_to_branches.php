<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciudad ESTRUCTURADA de la sede (código DANE), además del `city` de texto libre
 * que ya existía. La sede tiene una ciudad y —por regla del negocio— los
 * domicilios de esa sede solo se hacen a esa ciudad: el checkout de domicilio
 * hereda esta ciudad en vez de pedírsela al cliente.
 *
 * El `city` (nombre) se mantiene por compatibilidad; el controller lo deriva del
 * municipio elegido al guardar `municipality_dane_code`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->char('municipality_dane_code', 5)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('municipality_dane_code');
        });
    }
};
