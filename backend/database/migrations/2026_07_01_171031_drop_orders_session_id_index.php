<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `orders.session_id` es una columna solo-escritura (placeholder legacy
 * `caja-<uuid>` / espejo de table_session_id): ningún código la lee ni la
 * filtra. El índice solo encarecía cada INSERT/UPDATE de la tabla más
 * caliente. La columna se conserva (nullable) por historial; los writers
 * dejaron de poblarla en este mismo cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_session_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('session_id', 'orders_session_id_index');
        });
    }
};
