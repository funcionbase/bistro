<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag de fraude INFORMATIVO por cliente (plan-mejoras-chat F7, CA5).
 *
 * Se incrementa con cada entrega fallida / no-show. Solo señala en la UI
 * (badge rojo en chat/ficha) — NO restringe el checkout público (decisión de
 * producto: el cajero decide, p. ej. exigir transferencia antes de aprobar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedInteger('no_show_count')->default(0);
            $table->timestamp('fraud_flagged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['no_show_count', 'fraud_flagged_at']);
        });
    }
};
